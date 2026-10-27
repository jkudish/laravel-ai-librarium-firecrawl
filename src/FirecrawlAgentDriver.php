<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

/** Agent's durable binding; Interact remains an inline Driver. */
final readonly class FirecrawlAgentDriver implements DurableDriver
{
    public function __construct(private FirecrawlDriver $driver) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return array_replace(FirecrawlDriver::profileOptionRules(), ['mode' => ['required', 'string', 'in:agent']]);
    }

    public function run(DriverRequest $request): ResearchResult
    {
        return $this->driver->run($request);
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        return $this->driver->submitDurable($request);
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        return $this->driver->retrieveDurable($request, $reference);
    }
}
