<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Carbon\CarbonImmutable;
use Firecrawl\Models\AgentOptions;
use Firecrawl\Models\AgentStatusResponse;
use Firecrawl\Models\LocationConfig;
use Firecrawl\Models\ScrapeOptions;
use Firecrawl\Models\WebhookConfig;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;
use Jkudish\LaravelAiLibrariumFirecrawl\Http\PromptInteractClient;
use Throwable;

final readonly class FirecrawlDriver implements Driver
{
    private const int LIBRARIUM_CEILING_SECONDS = 7200;

    public function __construct(
        private CreatesFirecrawlClient $clients,
        private PromptInteractClient $interact,
        private FirecrawlResultMapper $mapper,
        private WebhookSignalStore $webhooks,
    ) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:interact,agent'],
            'target_url' => ['required', 'url:http,https', 'max:2048'],
            'surface' => ['required', 'string', 'min:1', 'max:255'],
            'locale' => ['sometimes', 'string', 'min:2', 'max:35'],
            'country' => ['sometimes', 'string', 'size:2', 'uppercase'],
            'device' => ['sometimes', 'string', 'in:desktop,mobile'],
            'authentication' => ['required', 'string', 'in:anonymous'],
            'personalization' => ['sometimes', 'string', 'in:present,absent,unknown'],
            'account_context' => ['sometimes', 'string', 'in:signed_out,unknown'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);

        try {
            return $request->profile->options['mode'] === 'interact'
                ? $this->runInteract($request)
                : $this->runAgent($request);
        } catch (DriverException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DriverException('firecrawl.provider', 'Firecrawl could not complete the surface observation.');
        }
    }

    private function runInteract(DriverRequest $request): ResearchResult
    {
        $options = $request->profile->options;
        $firecrawl = $this->clients->forRequest($request);
        $country = $this->option($options, 'country');
        $locale = $this->option($options, 'locale');
        $location = $country !== null || $locale !== null
            ? LocationConfig::with(country: $country, languages: $locale === null ? null : [$locale])
            : null;
        $document = $firecrawl->scrape(
            $this->requiredOption($options, 'target_url'),
            ScrapeOptions::with(
                formats: ['markdown'],
                mobile: $this->option($options, 'device') === 'mobile',
                location: $location,
                removeBase64Images: true,
            ),
        );
        $this->ensureBeforeDeadline($request, 'Firecrawl did not finish scraping before the research deadline.');
        $scrapeId = $document->getMetadata()['scrapeId'] ?? null;
        if (! is_string($scrapeId) || trim($scrapeId) === '') {
            throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned no scrape identifier.');
        }

        try {
            $request->progress('Firecrawl is interacting with the configured consumer surface.');
            $response = $this->interact->interact($request, $scrapeId, $this->interactionPrompt($request));
            $output = $response['output'] ?? null;
            if (! is_string($output)) {
                throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned no interaction output.');
            }

            return $this->mapper->result($request, $this->mapper->decode($output));
        } finally {
            try {
                $this->clients->forRequest($request)->stopInteractiveBrowser($scrapeId);
            } catch (Throwable) {
                // Cleanup is best effort and must neither exceed the active deadline nor replace the observation.
            }
        }
    }

    private function runAgent(DriverRequest $request): ResearchResult
    {
        $startedAt = CarbonImmutable::now();
        $deadline = $this->earlier($request->deadline, $startedAt->addSeconds(self::LIBRARIUM_CEILING_SECONDS));
        $webhook = $this->webhook($request);
        $start = $this->clients->forRequest($request)->startAgent(AgentOptions::with(
            urls: [$this->requiredOption($request->profile->options, 'target_url')],
            prompt: $this->agentPrompt($request),
            schema: $this->schema(),
            strictConstrainToURLs: true,
            webhook: $webhook,
        ));
        $this->ensureBefore($deadline);
        $jobId = $start->getId();
        if (! $start->isSuccess() || ! is_string($jobId) || trim($jobId) === '') {
            throw new DriverException('firecrawl.invalid_response', 'Firecrawl did not accept the Agent request.');
        }

        try {
            $this->webhooks->bind('firecrawl', $request->requestId, $request->profile->id, $jobId);
        } catch (Throwable) {
            // Polling is authoritative; webhook delivery is only a wake hint.
        }

        $expected = $this->configuredPositiveInt('firecrawl-librarium.expected_duration_seconds', 60);
        $pollInterval = $this->configuredPositiveInt('firecrawl-librarium.poll_interval_seconds', 2);
        $reportedDelayed = false;
        $reportedStalled = false;
        $usedWebhookSignal = false;
        $request->progress('Firecrawl Agent accepted the surface observation job.');

        while (true) {
            $status = $this->clients->forRequest($request)->getAgentStatus($jobId);
            $deadline = $this->providerDeadline($status, $deadline);
            $state = $status->getStatus();

            $this->ensureBefore($deadline);

            if ($state === 'completed') {
                if (! $status->isSuccess()) {
                    throw new DriverException('firecrawl.invalid_response', 'Firecrawl reported an inconsistent Agent result.');
                }
                $data = $status->getData();
                if (! is_array($data) || array_is_list($data)) {
                    throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned invalid Agent observation data.');
                }

                return $this->mapper->result($request, $data, $status->getModel(), $status->getCreditsUsed());
            }
            if (in_array($state, ['failed', 'cancelled'], true)) {
                throw new DriverException('firecrawl.agent_failed', 'Firecrawl could not complete the surface observation.');
            }
            if (! in_array($state, ['queued', 'processing', 'in_progress'], true)) {
                throw new DriverException('firecrawl.invalid_response', 'Firecrawl returned an unrecognized Agent state.');
            }

            $elapsed = (int) floor($startedAt->diffInSeconds(CarbonImmutable::now()));
            if (! $reportedDelayed && $elapsed >= $expected) {
                $request->progress('Firecrawl Agent is delayed but remains nonterminal.');
                $reportedDelayed = true;
            }
            if (! $reportedStalled && $elapsed >= $expected * 2) {
                $request->progress('Firecrawl Agent appears stalled but remains nonterminal.');
                $reportedStalled = true;
            }

            $signal = $this->webhooks->get($request->requestId, $request->profile->id);
            $usableSignal = ! $usedWebhookSignal
                && $signal !== null
                && $signal->provider === 'firecrawl'
                && $signal->providerReference === $jobId
                && in_array($signal->state, [ResearchState::Completed, ResearchState::Failed], true);
            if ($usableSignal) {
                $usedWebhookSignal = true;
            } else {
                $seconds = min($pollInterval, max(1, (int) floor(CarbonImmutable::now()->diffInSeconds($deadline, false))));
                Sleep::for($seconds)->seconds();
            }
        }
    }

    private function validateRequest(DriverRequest $request): void
    {
        if ($request->profile->provider !== 'firecrawl'
            || $request->profile->observation !== ObservationMode::SurfaceSnapshot
            || $request->profile->resultKind !== ResultKind::GroundedAnswer
            || $request->profile->grounding !== GroundingPolicy::Optional
            || $request->profile->corpora->values()->all() !== [Corpus::Web]
            || $request->profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]) {
            throw new DriverException('firecrawl.invalid_profile', 'The Firecrawl Profile has incompatible surface semantics.', false);
        }
        if (blank($request->profile->credential)) {
            throw new DriverException('firecrawl.authentication', 'Firecrawl is not configured.');
        }
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('firecrawl.deadline_exceeded', 'The Firecrawl request could not start before the research deadline.');
        }

        $personalization = $request->profile->options['personalization'] ?? null;
        $accountContext = $request->profile->options['account_context'] ?? null;
        if (($personalization !== null && ! in_array($personalization, ['present', 'absent', 'unknown'], true))
            || ($accountContext !== null && ! in_array($accountContext, ['signed_out', 'unknown'], true))) {
            throw new DriverException(
                'firecrawl.invalid_options',
                'The Firecrawl consumer-declared context is invalid.',
                false,
            );
        }

        if (($request->profile->options['mode'] ?? null) === 'agent'
            && array_intersect(['locale', 'country', 'device'], array_keys($request->profile->options)) !== []) {
            throw new DriverException(
                'firecrawl.invalid_options',
                'Firecrawl Agent mode cannot guarantee locale, country, or device context; use Interact mode for those facts.',
                false,
            );
        }

    }

    private function interactionPrompt(DriverRequest $request): string
    {
        return $this->observationInstructions($request, 'Interact with the page to submit this query: '.$request->prompt);
    }

    private function agentPrompt(DriverRequest $request): string
    {
        return $this->observationInstructions($request, 'Observe the configured consumer surface for this query: '.$request->prompt);
    }

    private function observationInstructions(DriverRequest $request, string $task): string
    {
        $prompt = $task."\nReturn only a JSON object matching this contract: completed boolean; answer string or null; citations array of at most 20 objects with http(s) url and optional title/excerpt; challenge one of none/captcha/blocked/unknown; login_wall boolean; latency_ms nonnegative integer; artifacts array of at most 10 screenshot/recording/trace objects containing only kind and an http(s) reference URL. Do not include browser payloads, cookies, credentials, CDP URLs, interactive URLs, or base64 data. A challenge or login wall is an observation, not a universal claim.";
        if (mb_strlen($prompt) > 10_000) {
            throw new DriverException('firecrawl.invalid_prompt', 'The rendered Firecrawl prompt exceeds 10,000 characters.', false);
        }

        return $prompt;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['completed', 'answer', 'citations', 'challenge', 'login_wall', 'latency_ms'],
            'properties' => [
                'completed' => ['type' => 'boolean'],
                'answer' => ['type' => ['string', 'null'], 'maxLength' => 50000],
                'citations' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['url'],
                        'properties' => [
                            'url' => ['type' => 'string', 'maxLength' => 2048],
                            'title' => ['type' => ['string', 'null'], 'maxLength' => 500],
                            'excerpt' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                        ],
                    ],
                ],
                'challenge' => ['type' => 'string', 'enum' => ['none', 'captcha', 'blocked', 'unknown']],
                'login_wall' => ['type' => 'boolean'],
                'latency_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 7200000],
                'artifacts' => [
                    'type' => 'array',
                    'maxItems' => 10,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['kind', 'url'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['screenshot', 'recording', 'trace']],
                            'url' => ['type' => 'string', 'maxLength' => 2048],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function webhook(DriverRequest $request): ?WebhookConfig
    {
        $url = config('firecrawl-librarium.webhook.url');
        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return WebhookConfig::with(
            url: $url,
            metadata: ['request_id' => $request->requestId, 'profile' => $request->profile->id],
            events: ['completed', 'failed'],
        );
    }

    private function providerDeadline(AgentStatusResponse $status, CarbonImmutable $current): CarbonImmutable
    {
        $expiresAt = $status->getExpiresAt();
        if (! is_string($expiresAt)) {
            return $current;
        }

        try {
            $provider = CarbonImmutable::parse($expiresAt);

            return $this->earlier($current, $provider);
        } catch (Throwable) {
            return $current;
        }
    }

    private function earlier(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->lessThanOrEqualTo($second) ? $first : $second;
    }

    /** @param array<string, mixed> $options */
    private function requiredOption(array $options, string $key): string
    {
        return $this->option($options, $key)
            ?? throw new DriverException('firecrawl.invalid_options', 'The Firecrawl Profile options are invalid.', false);
    }

    /** @param array<string, mixed> $options */
    private function option(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function configuredPositiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function ensureBeforeDeadline(DriverRequest $request, string $message): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('firecrawl.deadline_exceeded', $message);
        }
    }

    private function ensureBefore(CarbonImmutable $deadline): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
            throw new DriverException('firecrawl.deadline_exceeded', 'Firecrawl did not complete before the trustworthy deadline.');
        }
    }
}
