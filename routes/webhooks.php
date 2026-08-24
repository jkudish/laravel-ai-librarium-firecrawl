<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jkudish\LaravelAiLibrariumFirecrawl\Webhooks\FirecrawlWebhookController;

Route::post('/librarium/webhooks/firecrawl', FirecrawlWebhookController::class)
    ->name('librarium.webhooks.firecrawl');
