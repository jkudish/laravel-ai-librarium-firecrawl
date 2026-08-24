<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Tests;

use Firecrawl\Laravel\FirecrawlServiceProvider;
use Jkudish\LaravelAiLibrarium\LaravelAiLibrariumServiceProvider;
use Jkudish\LaravelAiLibrariumFirecrawl\FirecrawlLibrariumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiLibrariumServiceProvider::class,
            FirecrawlServiceProvider::class,
            FirecrawlLibrariumServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('firecrawl.api_key', 'fc-test-key');
        $app['config']->set('firecrawl.api_url', 'https://api.firecrawl.test');
        $app['config']->set('firecrawl-librarium.api_url', 'https://api.firecrawl.test');
        $app['config']->set('firecrawl-librarium.allow_custom_api_url', true);
    }
}
