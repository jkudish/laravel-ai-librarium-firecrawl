#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrariumFirecrawl\Tools\PrWorkflow;

require __DIR__.'/PrWorkflow.php';

$arguments = array_slice($_SERVER['argv'], 1);
$approvedSha = count($arguments) === 2 && $arguments[0] === '--approved-sha' ? $arguments[1] : null;

try {
    $result = (new PrWorkflow)->signoff($approvedSha);
    fwrite(STDOUT, "Signed off {$result['sha']}\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
