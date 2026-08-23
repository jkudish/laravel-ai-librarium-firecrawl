<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl;

use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
        $this->registerConfiguredProfile();
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/firecrawl-librarium.php' => config_path('firecrawl-librarium.php'),
            ], 'firecrawl-librarium-config');
        }
    }

    private function registerConfiguredProfile(): void
    {
        if (config('firecrawl-librarium.register_profile') !== true) {
            return;
        }

        $id = config('firecrawl-librarium.profile_id');
        $profile = config('firecrawl-librarium.profile');
        if (! is_string($id) || $id === '' || ! is_array($profile)) {
            return;
        }

        $profiles = config('librarium.profiles', []);
        if (! is_array($profiles) || array_key_exists($id, $profiles)) {
            return;
        }

        if (blank($profile['credential'] ?? null)) {
            $profile['credential'] = config('firecrawl.api_key');
        }

        config()->set('librarium.profiles.'.$id, $profile);
    }
}
