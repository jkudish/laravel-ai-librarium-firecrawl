<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;

final readonly class FirecrawlResultMapper
{
    private const int MAX_CREDITS_USED = 2_147_483_647;

    public function __construct(private ValidationFactory $validator) {}

    /** @return array<array-key, mixed> */
    public function decode(string $output): array
    {
        try {
            $value = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned invalid observation JSON.');
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned invalid observation JSON.');
        }

        $this->validate($value);

        return $value;
    }

    /** @param array<array-key, mixed> $observation */
    public function result(
        DriverRequest $request,
        array $observation,
        ?string $model = null,
        ?int $creditsUsed = null,
        ?string $mode = null,
        ?string $cleanup = null,
        ?int $providerOperationsStarted = null,
    ): ResearchResult {
        $this->validate($observation);
        $options = $request->profile->options;
        $challenge = $this->string($observation, 'challenge');
        $loginWall = $observation['login_wall'] === true;
        $answer = $this->nullableString($observation, 'answer');
        $content = $answer ?? match (true) {
            $loginWall => 'The observed surface presented a login wall.',
            $challenge !== 'none' => 'The observed surface presented a '.$challenge.' challenge.',
            default => throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned no completed surface answer.'),
        };

        $citations = [];
        foreach ($observation['citations'] as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $citations[] = Citation::make(
                derivation: CitationDerivation::CollectorExtracted,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $this->string($citation, 'url'),
                    title: $this->nullableString($citation, 'title'),
                ),
                excerpt: $this->nullableString($citation, 'excerpt'),
            );
        }

        $context = array_filter([
            'locale' => $this->optionString($options, 'locale'),
            'country' => $this->optionString($options, 'country'),
            'device' => $this->optionString($options, 'device'),
            'authentication' => Authentication::from($this->optionString($options, 'authentication') ?? 'anonymous'),
        ], static fn (mixed $value): bool => $value !== null);
        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $content,
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
                collector: 'firecrawl',
                surface: $this->optionString($options, 'surface'),
                context: $context,
            ),
            citations: collect($citations),
            completedAt: $now,
            model: $model,
            providerMeta: (object) array_filter([
                'latency_ms' => $observation['latency_ms'],
                'challenge' => $challenge,
                'login_wall' => $loginWall,
                'consumer_declared_context' => array_filter([
                    'personalization' => $this->optionString($options, 'personalization'),
                    'account_context' => $this->optionString($options, 'account_context'),
                ], static fn (mixed $value): bool => $value !== null),
                'evidence_receipts' => $this->artifactReceipts($observation['artifacts'] ?? []),
                'operation_receipt' => $this->operationReceipt($mode, $cleanup, $providerOperationsStarted),
                'credits_used' => $this->boundedCredits($creditsUsed),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /** @return array{mode: string, stage: 'observation', cleanup: string, provider_operations_started: int}|null */
    private function operationReceipt(?string $mode, ?string $cleanup, ?int $providerOperationsStarted): ?array
    {
        if (! in_array($mode, ['interact', 'agent'], true)
            || ! in_array($cleanup, ['completed', 'failed', 'not_started', 'not_applicable'], true)
            || $providerOperationsStarted === null
            || $providerOperationsStarted < 0
            || $providerOperationsStarted > 10_000) {
            return null;
        }

        return [
            'mode' => $mode,
            'stage' => 'observation',
            'cleanup' => $cleanup,
            'provider_operations_started' => $providerOperationsStarted,
        ];
    }

    private function boundedCredits(?int $creditsUsed): ?int
    {
        return $creditsUsed !== null && $creditsUsed >= 0 && $creditsUsed <= self::MAX_CREDITS_USED
            ? $creditsUsed
            : null;
    }

    /** @param array<array-key, mixed> $value */
    private function validate(array $value): void
    {
        try {
            $this->validator->make($value, [
                'completed' => ['required', 'boolean'],
                'answer' => ['nullable', 'string', 'min:1', 'max:50000'],
                'citations' => ['required', 'array', 'list', 'max:20'],
                'citations.*' => ['array:url,title,excerpt'],
                'citations.*.url' => ['required', 'url:http,https', 'max:2048'],
                'citations.*.title' => ['nullable', 'string', 'min:1', 'max:500'],
                'citations.*.excerpt' => ['nullable', 'string', 'min:1', 'max:1000'],
                'challenge' => ['required', 'string', 'in:none,captcha,blocked,unknown'],
                'login_wall' => ['required', 'boolean'],
                'latency_ms' => ['required', 'integer', 'min:0', 'max:7200000'],
                'artifacts' => ['sometimes', 'array', 'list', 'max:10'],
                'artifacts.*' => ['array:kind,url'],
                'artifacts.*.kind' => ['required', 'string', 'in:screenshot,recording,trace'],
                'artifacts.*.url' => ['required', 'url:http,https', 'max:2048'],
            ])->after(function ($validator) use ($value): void {
                if (array_diff(array_keys($value), ['completed', 'answer', 'citations', 'challenge', 'login_wall', 'latency_ms', 'artifacts']) !== []) {
                    $validator->errors()->add('observation', 'contains an unknown field.');
                }
                if (($value['challenge'] ?? null) === 'none'
                    && ($value['login_wall'] ?? null) === false
                    && (($value['completed'] ?? null) !== true || blank($value['answer'] ?? null))) {
                    $validator->errors()->add('completed', 'requires an answer when no challenge or login wall was observed.');
                }
            })->validate();
        } catch (ValidationException $exception) {
            $fields = implode(', ', array_slice(array_keys($exception->errors()), 0, 5));
            $suffix = $fields === '' ? '' : ' Invalid fields: '.$fields.'.';

            throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned an invalid surface observation.'.$suffix);
        }
    }

    /** @param array<array-key, mixed> $value */
    private function string(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (! is_string($item)) {
            throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned an invalid surface observation.');
        }

        return $item;
    }

    /** @param array<array-key, mixed> $value */
    private function nullableString(array $value, string $key): ?string
    {
        $item = $value[$key] ?? null;

        return is_string($item) && $item !== '' ? $item : null;
    }

    /** @param array<string, mixed> $options */
    private function optionString(array $options, string $key): ?string
    {
        return $this->nullableString($options, $key);
    }

    /**
     * @return list<array{kind: string, reference_sha256: string, reference_state: 'retained'|'redacted', reference?: string}>
     */
    private function artifactReceipts(mixed $artifacts): array
    {
        if (! is_array($artifacts)) {
            throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned an invalid surface observation.');
        }

        $receipts = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                throw new DriverException('firecrawl.invalid_output', 'Firecrawl returned an invalid surface observation.');
            }

            $kind = $this->string($artifact, 'kind');
            $reference = $this->string($artifact, 'url');
            $receipt = [
                'kind' => $kind,
                'reference_sha256' => hash('sha256', $reference),
                'reference_state' => 'redacted',
            ];

            if ($this->isSafeArtifactReference($reference)) {
                $receipt['reference'] = $reference;
                $receipt['reference_state'] = 'retained';
            }

            $receipts[] = $receipt;
        }

        return $receipts;
    }

    private function isSafeArtifactReference(string $reference): bool
    {
        $parts = parse_url($reference);
        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        $publicReferences = config('firecrawl-librarium.public_artifact_references', []);
        if (! is_array($publicReferences) || ! in_array($reference, $publicReferences, true)) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        $capabilityShape = strtolower($host.($parts['path'] ?? ''));
        for ($iteration = 0; $iteration < 4; $iteration++) {
            $decoded = rawurldecode($capabilityShape);
            if ($decoded === $capabilityShape) {
                break;
            }
            $capabilityShape = $decoded;
        }

        $hasOpaqueCapabilitySegment = array_any(
            preg_split('/[\/._-]+/', $capabilityShape) ?: [],
            static fn (string $segment): bool => strlen($segment) >= 24
                && preg_match('/^[a-z0-9]+$/', $segment) === 1,
        );

        return preg_match('/(?:^|[^a-z0-9])(cdp|browser|interactive|live[_-]?view|session|token|secret)(?:$|[^a-z0-9])/', $capabilityShape) !== 1
            && ! str_contains($capabilityShape, '%')
            && ! $hasOpaqueCapabilitySegment
            && $host !== 'firecrawl.dev'
            && ! str_ends_with($host, '.firecrawl.dev');
    }
}
