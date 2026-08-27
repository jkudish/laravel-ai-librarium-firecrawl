<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrariumFirecrawl\Tools\PrWorkflow;

require_once dirname(__DIR__, 2).'/tools/PrWorkflow.php';

const PR_SHA = '1111111111111111111111111111111111111111';
const PR_OTHER_SHA = '2222222222222222222222222222222222222222';

/** @return array{status: int, stdout: string, stderr: string} */
function prSuccess(string $stdout = ''): array
{
    return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
}

/** @return array{status: int, stdout: string, stderr: string} */
function prFailure(string $stderr): array
{
    return ['status' => 1, 'stdout' => '', 'stderr' => $stderr];
}

/**
 * @param  list<string>  $headSequence
 * @param  list<string>  $worktreeSequence
 * @return array{calls: ArrayObject<int, array{command: string, arguments: list<string>, options: array<string, mixed>}>, path: string, run: Closure}
 */
function prRunner(
    array $headSequence = [PR_SHA],
    array $worktreeSequence = [''],
    array|string $pullRequest = ['headRefOid' => PR_SHA, 'state' => 'OPEN'],
    string $extension = "gh signoff\tbasecamp/gh-signoff\tv1.0.0",
    ?string $failingCheck = null,
    string $phpVersion = '8.4.12',
): array {
    $directory = sys_get_temp_dir().'/firecrawl-pr-workflow-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    prTemporaryDirectories()->append($directory);
    $path = $directory.'/'.PR_SHA.'.json';
    $calls = new ArrayObject;
    $headIndex = 0;
    $worktreeIndex = 0;

    $run = function (string $command, array $arguments, array $options = []) use (
        $calls,
        $extension,
        $failingCheck,
        $headSequence,
        &$headIndex,
        $path,
        $phpVersion,
        $pullRequest,
        $worktreeSequence,
        &$worktreeIndex,
    ): array {
        $calls[] = compact('command', 'arguments', 'options');

        if ($command === 'git' && $arguments === ['rev-parse', 'HEAD']) {
            $value = $headSequence[min($headIndex, count($headSequence) - 1)];
            $headIndex++;

            return prSuccess($value."\n");
        }

        if ($command === 'git' && $arguments === ['status', '--porcelain=v1', '--untracked-files=all']) {
            $value = $worktreeSequence[min($worktreeIndex, count($worktreeSequence) - 1)];
            $worktreeIndex++;

            return prSuccess($value);
        }

        if ($command === 'git' && $arguments[0] === 'rev-parse' && $arguments[1] === '--git-path') {
            return prSuccess($path."\n");
        }

        if ($command === 'php' && $arguments[0] === '-r') {
            return prSuccess(json_encode(['Linux', 'x86_64', $phpVersion], JSON_THROW_ON_ERROR));
        }

        if ($command === 'composer' && $arguments === ['--version', '--no-ansi']) {
            return prSuccess('Composer version 2.8.12 2026-08-01 00:00:00');
        }

        if ($command === 'gh' && $arguments === ['extension', 'list']) {
            return prSuccess($extension."\n");
        }

        if ($command === 'gh' && $arguments[0] === 'pr') {
            return prSuccess(is_string($pullRequest) ? $pullRequest : json_encode($pullRequest, JSON_THROW_ON_ERROR));
        }

        if ($failingCheck === $command.' '.implode(' ', $arguments)) {
            return prFailure('intentional failure');
        }

        return prSuccess();
    };

    return compact('calls', 'path', 'run');
}

/** @return array<string, mixed> */
function prValidReceipt(PrWorkflow $workflow, array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'sha' => PR_SHA,
        'planId' => $workflow->planId(),
        'success' => true,
        'completedAt' => '2026-08-27T12:00:00+00:00',
        'runtime' => [
            'policy' => ['phpConstraint' => '^8.4', 'phpMinimum' => '8.4.0'],
            'actual' => [
                'platform' => 'Linux',
                'architecture' => 'x86_64',
                'php' => '8.4.12',
                'composer' => '2.8.12',
            ],
        ],
    ], $overrides);
}

function prWriteReceipt(string $path, array|string $receipt): void
{
    file_put_contents($path, is_string($receipt) ? $receipt : json_encode($receipt, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}

/** @return ArrayObject<int, string> */
function prTemporaryDirectories(): ArrayObject
{
    static $directories;

    return $directories ??= new ArrayObject;
}

afterEach(function (): void {
    $directories = array_reverse(prTemporaryDirectories()->getArrayCopy());
    prTemporaryDirectories()->exchangeArray([]);

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
});

it('owns the ordered offline package verification plan and PHP policy', function (): void {
    $workflow = new PrWorkflow(prRunner()['run'], ['PATH' => '/usr/bin']);

    expect(PrWorkflow::CHECK_STEPS)->toBe([
        ['command' => 'composer', 'arguments' => ['validate', '--strict']],
        ['command' => 'composer', 'arguments' => ['check-platform-reqs']],
        ['command' => 'composer', 'arguments' => ['test']],
        ['command' => 'composer', 'arguments' => ['analyse']],
        ['command' => 'composer', 'arguments' => ['format']],
        ['command' => 'git', 'arguments' => ['diff', '--exit-code']],
    ])->and($workflow->runtimePolicy())->toBe([
        'phpConstraint' => '^8.4',
        'phpMinimum' => '8.4.0',
    ])->and($workflow->planId())->toMatch('/^[0-9a-f]{64}$/');
});

it('constructs an allowlist environment without ambient credentials or config home', function (): void {
    $environment = PrWorkflow::candidateEnvironment([
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'github-secret',
        'GH_SIGNOFF_TOKEN' => 'signoff-secret',
        'FIRECRAWL_API_KEY' => 'provider-secret',
        'OPENAI_API_KEY' => 'other-provider-secret',
        'COMPOSER_AUTH' => 'composer-secret',
    ], '/tmp/disposable-home');

    expect($environment)->toBe([
        'PATH' => '/usr/bin',
        'HOME' => '/tmp/disposable-home',
        'APP_ENV' => 'testing',
        'CI' => '1',
        'COMPOSER_NO_INTERACTION' => '1',
    ]);
});

it('writes an atomic exact-SHA mode-0600 receipt after all checks pass', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'github-secret',
        'GH_SIGNOFF_TOKEN' => 'signoff-secret',
        'FIRECRAWL_API_KEY' => 'provider-secret',
    ]);
    $result = $workflow->check(new DateTimeImmutable('2026-08-27T12:00:00+00:00'));

    expect($result['path'])->toBe($mock['path'])
        ->and(fileperms($mock['path']) & 0777)->toBe(0600)
        ->and(json_decode((string) file_get_contents($mock['path']), true, flags: JSON_THROW_ON_ERROR))->toBe($result['receipt'])
        ->and($result['receipt'])->toBe(prValidReceipt($workflow));

    $checkCalls = array_values(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true,
    ));

    expect(array_map(fn (array $call): array => [$call['command'], $call['arguments']], $checkCalls))
        ->toBe(array_map(fn (array $step): array => [$step['command'], $step['arguments']], PrWorkflow::CHECK_STEPS));

    foreach ($checkCalls as $call) {
        expect($call['options']['env'])->not->toHaveKeys(['GH_TOKEN', 'GH_SIGNOFF_TOKEN', 'FIRECRAWL_API_KEY'])
            ->and($call['options']['env']['HOME'])->toStartWith(sys_get_temp_dir().'/firecrawl-pr-check-home-')
            ->and($call['options']['env']['HOME'])->not->toBe('/home/user');
    }

    expect(glob(dirname($mock['path']).'/*.tmp') ?: [])->toBe([]);
});

it('stops at the first failed ordered check and writes no receipt', function (): void {
    $mock = prRunner(failingCheck: 'composer test');

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, 'composer test failed');
    expect(file_exists($mock['path']))->toBeFalse();

    $inherited = array_values(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true,
    ));
    expect(array_map(fn (array $call): string => $call['command'].' '.implode(' ', $call['arguments']), $inherited))->toBe([
        'composer validate --strict',
        'composer check-platform-reqs',
        'composer test',
    ]);
});

it('rejects a dirty candidate before running checks', function (): void {
    $mock = prRunner(worktreeSequence: ['?? accidental.txt']);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, 'worktree must be clean');
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => ($call['options']['inherit'] ?? false) === true))->toBe([]);
});

it('does not write a receipt when HEAD or the worktree changes during checks', function (array $heads, array $worktrees, string $message): void {
    $mock = prRunner(headSequence: $heads, worktreeSequence: $worktrees);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, $message);
    expect(file_exists($mock['path']))->toBeFalse();
})->with([
    'HEAD drift' => [[PR_SHA, PR_OTHER_SHA], ['', ''], 'HEAD changed'],
    'worktree drift' => [[PR_SHA], ['', ' M README.md'], 'worktree must be clean'],
]);

it('requires an explicit full lowercase SHA matching HEAD', function (?string $approvedSha, string $message): void {
    $mock = prRunner();

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->signoff($approvedSha))
        ->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'missing' => [null, 'full 40-character lowercase'],
    'partial' => ['1111111', 'full 40-character lowercase'],
    'uppercase' => [str_repeat('A', 40), 'full 40-character lowercase'],
    'different' => [PR_OTHER_SHA, 'approved SHA does not match'],
]);

it('rejects a malformed Git HEAD and a dirty signoff tree before reading evidence or GitHub', function (array $heads, array $worktrees, string $message): void {
    $mock = prRunner(headSequence: $heads, worktreeSequence: $worktrees);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->signoff(PR_SHA))
        ->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'malformed HEAD' => [['HEAD'], [''], 'full lowercase SHA'],
    'dirty tree' => [[PR_SHA], ['?? accidental.txt'], 'worktree must be clean'],
]);

it('rejects missing, malformed, and stale receipts before GitHub access', function (array|string|null $receipt, string $message): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);

    if ($receipt !== null) {
        prWriteReceipt($mock['path'], is_array($receipt) ? prValidReceipt($workflow, $receipt) : $receipt);
    }

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'missing' => [null, 'No readable verification receipt'],
    'malformed JSON' => ['{', 'malformed JSON'],
    'wrong version' => [['version' => 2], 'malformed, stale'],
    'wrong SHA' => [['sha' => PR_OTHER_SHA], 'malformed, stale'],
    'wrong plan' => [['planId' => str_repeat('a', 64)], 'malformed, stale'],
    'unsuccessful' => [['success' => false], 'malformed, stale'],
    'bad timestamp' => [['completedAt' => 'yesterday'], 'malformed, stale'],
    'wrong policy' => [['runtime' => ['policy' => ['phpConstraint' => '^8.5', 'phpMinimum' => '8.5.0']]], 'malformed, stale'],
    'missing runtime' => [['runtime' => null], 'malformed, stale'],
    'malformed runtime' => [['runtime' => ['actual' => ['php' => 'not-a-version']]], 'malformed, stale'],
]);

it('rejects a receipt whose permission mode is no longer 0600', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));
    chmod($mock['path'], 0644);

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'mode 0600');
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
});

it('rejects symlinked and oversized receipts', function (string $kind, string $message): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);

    if ($kind === 'symlink') {
        $target = dirname($mock['path']).'/target.json';
        prWriteReceipt($target, prValidReceipt($workflow));
        symlink($target, $mock['path']);
    } else {
        prWriteReceipt($mock['path'], str_repeat('x', 65_537));
    }

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'symlink' => ['symlink', 'No readable verification receipt'],
    'oversized' => ['oversized', 'malformed or too large'],
]);

it('rejects receipt runtime evidence that differs from the current runtime', function (): void {
    $mock = prRunner(phpVersion: '8.4.13');
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'runtime evidence');
});

it('requires the gh-signoff extension and a valid open PR at the SHA', function (string $extension, array|string $pullRequest, string $message): void {
    $mock = prRunner(extension: $extension, pullRequest: $pullRequest);
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => $call['command'] === 'gh' && $call['arguments'][0] === 'signoff',
    ))->toBe([]);
})->with([
    'missing extension' => ['', ['headRefOid' => PR_SHA, 'state' => 'OPEN'], 'extension is not installed'],
    'invalid PR JSON' => ["gh signoff\tbasecamp/gh-signoff\tv1", '{', 'invalid pull-request response'],
    'closed PR' => ["gh signoff\tbasecamp/gh-signoff\tv1", ['headRefOid' => PR_SHA, 'state' => 'CLOSED'], 'open pull request'],
    'mismatched PR head' => ["gh signoff\tbasecamp/gh-signoff\tv1", ['headRefOid' => PR_OTHER_SHA, 'state' => 'OPEN'], 'head does not match'],
]);

it('requires the dedicated token after read-only eligibility checks', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'GH_SIGNOFF_TOKEN is required');
});

it('rechecks the clean unchanged HEAD immediately before signoff', function (array $heads, array $worktrees, string $message): void {
    $mock = prRunner(headSequence: $heads, worktreeSequence: $worktrees);
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_SIGNOFF_TOKEN' => 'status-token',
    ]);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => $call['command'] === 'gh' && $call['arguments'][0] === 'signoff',
    ))->toBe([]);
})->with([
    'HEAD drift' => [[PR_SHA, PR_OTHER_SHA], ['', ''], 'HEAD changed'],
    'worktree drift' => [[PR_SHA], ['', ' M README.md'], 'worktree must be clean'],
]);

it('maps the dedicated token only for the exact unforced signoff command', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'ambient-token',
        'GH_SIGNOFF_TOKEN' => 'status-token',
        'FIRECRAWL_API_KEY' => 'provider-token',
    ]);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect($workflow->signoff(PR_SHA))->toBe(['path' => $mock['path'], 'sha' => PR_SHA]);

    $githubCalls = array_values(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'));
    $signoffCall = $githubCalls[array_key_last($githubCalls)];

    expect($signoffCall['arguments'])->toBe(['signoff', '--commit', PR_SHA])
        ->and($signoffCall['arguments'])->not->toContain('--force')
        ->and($signoffCall['arguments'])->not->toContain('-f')
        ->and($signoffCall['options']['env']['GH_TOKEN'])->toBe('status-token')
        ->and($signoffCall['options']['env']['GH_REPO'])->toBe(PrWorkflow::EXPECTED_REPOSITORY)
        ->and($signoffCall['options']['env'])->not->toHaveKeys(['GH_SIGNOFF_TOKEN', 'FIRECRAWL_API_KEY']);

    $pullRequestCalls = array_values(array_filter($githubCalls, fn (array $call): bool => $call['arguments'][0] === 'pr'));
    expect($pullRequestCalls)->toHaveCount(2);

    foreach ($pullRequestCalls as $call) {
        expect($call['arguments'])->toBe([
            'pr',
            'view',
            '--repo',
            PrWorkflow::EXPECTED_REPOSITORY,
            '--json',
            'headRefOid,state',
        ]);
    }

    foreach (array_slice($mock['calls']->getArrayCopy(), 0, -1) as $call) {
        expect($call['options']['env'])->not->toHaveKeys(['GH_TOKEN', 'GH_SIGNOFF_TOKEN', 'FIRECRAWL_API_KEY']);
    }
});
