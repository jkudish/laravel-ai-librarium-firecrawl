<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Webhooks;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use Throwable;

final readonly class FirecrawlWebhookController
{
    private const int MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private WebhookSignalStore $webhooks,
        private Repository $cache,
    ) {}

    public function __invoke(Request $request): Response
    {
        $secret = config('firecrawl-librarium.webhook.secret');
        if (! is_string($secret) || strlen($secret) < 16) {
            return new Response(status: 404);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new Response(status: 413);
        }

        $signature = $request->header('X-Firecrawl-Signature');
        if (! is_string($signature) || preg_match('/^sha256=([a-f0-9]{64})$/', $signature, $matches) !== 1) {
            return new Response(status: 401);
        }
        if (! hash_equals(hash_hmac('sha256', $body, $secret), $matches[1])) {
            return new Response(status: 401);
        }

        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response(status: 422);
        }
        if (! is_array($event) || array_is_list($event)) {
            return new Response(status: 422);
        }

        $state = match ($event['type'] ?? null) {
            'agent.completed' => ResearchState::Completed,
            'agent.failed' => ResearchState::Failed,
            default => null,
        };
        if ($state === null) {
            return new Response(status: 204);
        }

        $jobId = $event['id'] ?? null;
        $webhookId = $event['webhookId'] ?? null;
        if (! is_string($jobId) || trim($jobId) === '' || strlen($jobId) > 1024
            || ! is_string($webhookId) || trim($webhookId) === '' || strlen($webhookId) > 1024) {
            return new Response(status: 422);
        }

        $deliveryKey = 'librarium:firecrawl:webhook:'.hash('sha256', $jobId."\0".$webhookId);
        $ttl = config('firecrawl-librarium.webhook_idempotency_ttl', 10800);
        $ttl = is_int($ttl) && $ttl > 0 ? $ttl : 10800;
        if (! $this->cache->add($deliveryKey, true, $ttl)) {
            return new Response(status: 204);
        }

        try {
            $this->webhooks->record('firecrawl', $jobId, $webhookId, $state);
        } catch (Throwable) {
            $this->cache->forget($deliveryKey);

            return new Response(status: 422);
        }

        return new Response(status: 204);
    }
}
