#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrariumFirecrawl\Tools\PrWorkflow;

require __DIR__.'/PrWorkflow.php';

try {
    $result = (new PrWorkflow)->check();
    fwrite(STDOUT, "Verified {$result['receipt']['sha']}\nPlan: {$result['receipt']['planId']}\nReceipt: {$result['path']}\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
