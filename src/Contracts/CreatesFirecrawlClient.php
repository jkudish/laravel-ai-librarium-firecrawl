<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Contracts;

use Firecrawl\Client\FirecrawlClient;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;

interface CreatesFirecrawlClient
{
    public function forRequest(DriverRequest|RecoveryRequest $request): FirecrawlClient;
}
