<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Illuminate\Support\ServiceProvider;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrariumFirecrawl\Contracts\CreatesFirecrawlClient;
use Jkudish\LaravelAiLibrariumFirecrawl\Http\PromptInteractClient;

final class FirecrawlLibrariumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/firecrawl-librarium.php', 'firecrawl-librarium');
        $this->app->singleton(PromptInteractClient::class);
        $this->app->singleton(CreatesFirecrawlClient::class, FirecrawlClientFactory::class);
        $this->app->singleton(FirecrawlResultMapper::class);
        $this->app->singleton(FirecrawlDriver::class);
        $this->app->singleton(FirecrawlAgentDriver::class);
    }

    public function boot(): void
    {
        if (blank(config('services.firecrawl.key'))) {
            config()->set('services.firecrawl.key', config('firecrawl.api_key'));
        }
        $this->app->make(LibrariumManager::class)->extendCollector('firecrawl', function (string $engine, array $settings): Profile {
            $defaults = config('firecrawl-librarium.options', []);
            $configured = $settings['options'] ?? [];
            $options = array_replace(
                is_array($defaults) ? $defaults : [],
                is_array($configured) ? $configured : [],
                array_intersect_key($settings, FirecrawlDriver::profileOptionRules()),
                ['target_url' => $settings['url'] ?? config('firecrawl-librarium.urls.'.$engine), 'surface' => $engine],
            );

            return $this->app->make(ProfileCatalog::class)->profile('firecrawl-'.$engine, [
                'driver' => ($options['mode'] ?? null) === 'agent' ? FirecrawlAgentDriver::class : FirecrawlDriver::class,
                'provider' => 'firecrawl', 'model' => null,
                'result_kind' => 'surface_observation', 'grounding' => 'optional',
                'observation' => 'surface_snapshot', 'corpora' => ['web'],
                'retrieval_methods' => ['surface_collector'], 'prompt' => '{{ query }}',
                'enabled' => ($settings['enabled'] ?? true) !== false,
                'options' => $options, 'credential' => $settings['key'] ?? null,
            ]);
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/firecrawl-librarium.php' => config_path('firecrawl-librarium.php'),
            ], 'firecrawl-librarium-config');
        }
    }
}
