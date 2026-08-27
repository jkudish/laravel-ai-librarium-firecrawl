<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrariumFirecrawl\Tools;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class PrWorkflow
{
    public const RECEIPT_VERSION = 1;

    public const EXPECTED_REPOSITORY = 'jkudish/laravel-ai-librarium-firecrawl';

    /** @var list<array{command: string, arguments: list<string>}> */
    public const CHECK_STEPS = [
        ['command' => 'composer', 'arguments' => ['validate', '--strict']],
        ['command' => 'composer', 'arguments' => ['check-platform-reqs']],
        ['command' => 'composer', 'arguments' => ['test']],
        ['command' => 'composer', 'arguments' => ['analyse']],
        ['command' => 'composer', 'arguments' => ['format']],
        ['command' => 'git', 'arguments' => ['diff', '--exit-code']],
    ];

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/D';

    private const VERSION_PATTERN = '/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/D';

    private const MAX_RECEIPT_BYTES = 65_536;

    /** @var callable(string, list<string>, array{env?: array<string, string>, inherit?: bool}): array{status: int, stdout: string, stderr: string} */
    private $runner;

    /** @var array<string, string> */
    private array $environment;

    /**
     * @param  null|callable(string, list<string>, array{env?: array<string, string>, inherit?: bool}): array{status: int, stdout: string, stderr: string}  $runner
     * @param  null|array<string, string>  $environment
     */
    public function __construct(?callable $runner = null, ?array $environment = null)
    {
        $this->runner = $runner ?? self::runProcess(...);
        $this->environment = $environment ?? self::ambientEnvironment();
    }

    /** @return array{path: string, receipt: array<string, mixed>} */
    public function check(?DateTimeImmutable $completedAt = null): array
    {
        $home = sys_get_temp_dir().'/firecrawl-pr-check-home-'.bin2hex(random_bytes(12));

        if (! mkdir($home, 0700) || ! chmod($home, 0700)) {
            throw new RuntimeException('Could not create the isolated verification home.');
        }

        $environment = self::candidateEnvironment($this->environment, $home);

        try {
            $sha = $this->headSha($environment);
            $this->requireCleanWorktree($environment);

            foreach (self::CHECK_STEPS as $step) {
                $this->successful(
                    ($this->runner)($step['command'], $step['arguments'], [
                        'env' => $environment,
                        'inherit' => true,
                    ]),
                    $step['command'].' '.implode(' ', $step['arguments']),
                );
            }

            $runtime = $this->collectRuntime($environment);
            $this->requireCleanWorktree($environment);

            if ($this->headSha($environment) !== $sha) {
                throw new RuntimeException('Git HEAD changed while verification was running.');
            }

            $receipt = [
                'version' => self::RECEIPT_VERSION,
                'sha' => $sha,
                'planId' => $this->planId(),
                'success' => true,
                'completedAt' => ($completedAt ?? new DateTimeImmutable)->format(DATE_ATOM),
                'runtime' => $runtime,
            ];
            $path = $this->receiptPathFor($sha, $environment);
            $this->writeReceipt($path, $receipt);

            return ['path' => $path, 'receipt' => $receipt];
        } finally {
            self::removeDirectory($home);
        }
    }

    /** @return array{path: string, sha: string} */
    public function signoff(?string $approvedSha): array
    {
        if (! is_string($approvedSha) || preg_match(self::SHA_PATTERN, $approvedSha) !== 1) {
            throw new RuntimeException('--approved-sha must be a full 40-character lowercase Git SHA.');
        }

        $readEnvironment = self::signoffReadEnvironment($this->environment);
        $this->requireCleanWorktree($readEnvironment);
        $sha = $this->headSha($readEnvironment);

        if ($sha !== $approvedSha) {
            throw new RuntimeException('The explicitly approved SHA does not match the current Git HEAD.');
        }

        $path = $this->receiptPathFor($sha, $readEnvironment);
        $receipt = $this->readReceipt($path);
        $runtimeHome = sys_get_temp_dir().'/firecrawl-pr-signoff-home-'.bin2hex(random_bytes(12));

        if (! mkdir($runtimeHome, 0700) || ! chmod($runtimeHome, 0700)) {
            throw new RuntimeException('Could not create the isolated runtime evidence home.');
        }

        try {
            $currentRuntime = $this->collectRuntime(self::candidateEnvironment($this->environment, $runtimeHome));
        } finally {
            self::removeDirectory($runtimeHome);
        }

        $this->requireValidReceipt($receipt, $sha, $currentRuntime);
        $this->requireSignoffExtension($readEnvironment);
        $this->requireOpenPullRequestAtSha($sha, $readEnvironment);

        $token = $this->environment['GH_SIGNOFF_TOKEN'] ?? '';

        if (trim($token) === '') {
            throw new RuntimeException('GH_SIGNOFF_TOKEN is required for the final signoff status write.');
        }

        $this->requireOpenPullRequestAtSha($sha, $readEnvironment);
        $this->requireCleanWorktree($readEnvironment);

        if ($this->headSha($readEnvironment) !== $sha) {
            throw new RuntimeException('Git HEAD changed while signoff eligibility was being checked.');
        }

        $signoffEnvironment = $readEnvironment;
        $signoffEnvironment['GH_TOKEN'] = $token;
        $signoffEnvironment['GH_REPO'] = self::EXPECTED_REPOSITORY;
        $this->successful(
            ($this->runner)('gh', ['signoff', '--commit', $sha], [
                'env' => $signoffEnvironment,
                'inherit' => true,
            ]),
            'gh signoff --commit '.$sha,
        );

        return ['path' => $path, 'sha' => $sha];
    }

    public function planId(): string
    {
        try {
            $encoded = json_encode([
                'steps' => self::CHECK_STEPS,
                'repository' => self::EXPECTED_REPOSITORY,
                'runtimePolicy' => $this->runtimePolicy(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the verification plan.', previous: $exception);
        }

        return hash('sha256', $encoded);
    }

    /** @return array{phpConstraint: string, phpMinimum: string} */
    public function runtimePolicy(): array
    {
        try {
            $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('composer.json does not contain valid JSON.', previous: $exception);
        }

        $constraint = $composer['require']['php'] ?? null;

        if (! is_string($constraint) || preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/D', $constraint, $matches) !== 1) {
            throw new RuntimeException('The PHP compatibility constraint is unsupported by the PR workflow.');
        }

        return [
            'phpConstraint' => $constraint,
            'phpMinimum' => $matches[1].'.'.$matches[2].'.'.($matches[3] ?? '0'),
        ];
    }

    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    public static function candidateEnvironment(array $ambient, string $home): array
    {
        return array_filter([
            'PATH' => $ambient['PATH'] ?? null,
            'HOME' => $home,
            'APP_ENV' => 'testing',
            'CI' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ], is_string(...));
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{policy: array{phpConstraint: string, phpMinimum: string}, actual: array{platform: string, architecture: string, php: string, composer: string}}
     */
    private function collectRuntime(array $environment): array
    {
        $phpOutput = $this->successful(
            ($this->runner)('php', ['-r', 'echo json_encode([PHP_OS_FAMILY, php_uname("m"), PHP_VERSION], JSON_THROW_ON_ERROR);'], ['env' => $environment]),
            'PHP runtime evidence',
        );

        try {
            $php = json_decode($phpOutput, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PHP returned malformed runtime evidence.', previous: $exception);
        }

        $composerOutput = $this->successful(
            ($this->runner)('composer', ['--version', '--no-ansi'], ['env' => $environment]),
            'Composer runtime evidence',
        );

        if (preg_match('/^Composer version (\S+)/', $composerOutput, $composerMatch) !== 1) {
            throw new RuntimeException('Composer returned an unrecognized version.');
        }

        $actual = [
            'platform' => $php[0] ?? null,
            'architecture' => $php[1] ?? null,
            'php' => $php[2] ?? null,
            'composer' => $composerMatch[1],
        ];

        $this->requireValidRuntimeActual($actual);

        /** @var array{platform: string, architecture: string, php: string, composer: string} $actual */
        return ['policy' => $this->runtimePolicy(), 'actual' => $actual];
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array{policy: array{phpConstraint: string, phpMinimum: string}, actual: array{platform: string, architecture: string, php: string, composer: string}}  $currentRuntime
     */
    private function requireValidReceipt(array $receipt, string $sha, array $currentRuntime): void
    {
        $completedAt = $receipt['completedAt'] ?? null;
        $runtime = $receipt['runtime'] ?? null;
        $actual = is_array($runtime) ? ($runtime['actual'] ?? null) : null;
        $policy = is_array($runtime) ? ($runtime['policy'] ?? null) : null;

        try {
            $validDate = is_string($completedAt) && (new DateTimeImmutable($completedAt))->format(DATE_ATOM) === $completedAt;
        } catch (\Exception) {
            $validDate = false;
        }

        if (
            ($receipt['version'] ?? null) !== self::RECEIPT_VERSION
            || ($receipt['sha'] ?? null) !== $sha
            || ($receipt['planId'] ?? null) !== $this->planId()
            || ($receipt['success'] ?? null) !== true
            || ! $validDate
            || $policy !== $this->runtimePolicy()
            || ! is_array($actual)
            || $runtime !== $currentRuntime
        ) {
            throw new RuntimeException('The verification receipt is malformed, stale, or does not match the current plan, SHA, and runtime evidence.');
        }

        $this->requireValidRuntimeActual($actual);
    }

    /** @param array<string, mixed> $actual */
    private function requireValidRuntimeActual(array $actual): void
    {
        if (
            ! is_string($actual['platform'] ?? null)
            || $actual['platform'] === ''
            || strlen($actual['platform']) > 32
            || ! is_string($actual['architecture'] ?? null)
            || $actual['architecture'] === ''
            || strlen($actual['architecture']) > 32
            || ! is_string($actual['php'] ?? null)
            || preg_match(self::VERSION_PATTERN, $actual['php']) !== 1
            || ! is_string($actual['composer'] ?? null)
            || preg_match(self::VERSION_PATTERN, $actual['composer']) !== 1
        ) {
            throw new RuntimeException('Runtime evidence is missing or malformed.');
        }
    }

    /** @return array<string, mixed> */
    private function readReceipt(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('No readable verification receipt exists for the current SHA: '.$path);
        }

        $permissions = fileperms($path);

        if (! is_int($permissions) || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('The verification receipt must have mode 0600.');
        }

        $size = filesize($path);

        if (! is_int($size) || $size > self::MAX_RECEIPT_BYTES) {
            throw new RuntimeException('The verification receipt is malformed or too large.');
        }

        try {
            $receipt = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The verification receipt contains malformed JSON.', previous: $exception);
        }

        if (! is_array($receipt)) {
            throw new RuntimeException('The verification receipt is malformed.');
        }

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    private function writeReceipt(string $path, array $receipt): void
    {
        $directory = dirname($path);

        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || ! chmod($directory, 0700)) {
            throw new RuntimeException('Could not create the receipt directory: '.$directory);
        }

        try {
            $contents = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the verification receipt.', previous: $exception);
        }

        $temporaryPath = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
        $handle = fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary verification receipt.');
        }

        try {
            if (! chmod($temporaryPath, 0600) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the verification receipt.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Could not flush the verification receipt.');
            }
        } finally {
            fclose($handle);
        }

        try {
            if (! rename($temporaryPath, $path) || ! chmod($path, 0600)) {
                throw new RuntimeException('Could not atomically install the verification receipt.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /** @param array<string, string> $environment */
    private function receiptPathFor(string $sha, array $environment): string
    {
        return $this->git(['rev-parse', '--git-path', 'librarium-firecrawl/pr-check/'.$sha.'.json'], $environment);
    }

    /** @param array<string, string> $environment */
    private function requireSignoffExtension(array $environment): void
    {
        $extensions = $this->successful(
            ($this->runner)('gh', ['extension', 'list'], ['env' => $environment]),
            'gh extension list',
        );

        if (preg_match('/(^|\s)basecamp\/gh-signoff(\s|$)/m', $extensions) !== 1) {
            throw new RuntimeException('The basecamp/gh-signoff extension is not installed.');
        }
    }

    /** @param array<string, string> $environment */
    private function requireOpenPullRequestAtSha(string $sha, array $environment): void
    {
        $pullRequest = $this->currentPullRequest($environment);

        if (($pullRequest['state'] ?? null) !== 'OPEN') {
            throw new RuntimeException('The current branch must have an open pull request.');
        }

        if (($pullRequest['headRefOid'] ?? null) !== $sha) {
            throw new RuntimeException('The open pull request head does not match the approved SHA.');
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function currentPullRequest(array $environment): array
    {
        $output = $this->successful(
            ($this->runner)('gh', ['pr', 'view', '--repo', self::EXPECTED_REPOSITORY, '--json', 'headRefOid,state'], ['env' => $environment]),
            'gh pr view',
        );

        try {
            $pullRequest = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub returned an invalid pull-request response.', previous: $exception);
        }

        if (! is_array($pullRequest)) {
            throw new RuntimeException('GitHub returned an invalid pull-request response.');
        }

        return $pullRequest;
    }

    /** @param array<string, string> $environment */
    private function requireCleanWorktree(array $environment): void
    {
        if ($this->git(['status', '--porcelain=v1', '--untracked-files=all'], $environment) !== '') {
            throw new RuntimeException('The worktree must be clean before verification or signoff.');
        }
    }

    /** @param array<string, string> $environment */
    private function headSha(array $environment): string
    {
        $sha = $this->git(['rev-parse', 'HEAD'], $environment);

        if (preg_match(self::SHA_PATTERN, $sha) !== 1) {
            throw new RuntimeException('Git HEAD did not resolve to a full lowercase SHA.');
        }

        return $sha;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function git(array $arguments, array $environment): string
    {
        return $this->successful(
            ($this->runner)('git', $arguments, ['env' => $environment]),
            'git '.implode(' ', $arguments),
        );
    }

    /** @param array{status: int, stdout: string, stderr: string} $result */
    private function successful(array $result, string $label): string
    {
        if ($result['status'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new RuntimeException($label.' failed'.($detail === '' ? '' : ': '.$detail));
        }

        return trim($result['stdout']);
    }

    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    private static function signoffReadEnvironment(array $ambient): array
    {
        return array_filter([
            'PATH' => $ambient['PATH'] ?? null,
            'HOME' => $ambient['HOME'] ?? null,
            'CI' => '1',
        ], is_string(...));
    }

    /** @return array<string, string> */
    private static function ambientEnvironment(): array
    {
        return array_filter(getenv(), is_string(...));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array{env?: array<string, string>, inherit?: bool}  $options
     * @return array{status: int, stdout: string, stderr: string}
     */
    private static function runProcess(string $command, array $arguments, array $options = []): array
    {
        $inherit = $options['inherit'] ?? false;
        $output = $inherit ? null : tmpfile();

        if (! $inherit && $output === false) {
            return ['status' => 1, 'stdout' => '', 'stderr' => 'Could not create process output storage.'];
        }

        if ($inherit) {
            $descriptors = [STDIN, STDOUT, STDERR];
        } else {
            if (! is_resource($output)) {
                return ['status' => 1, 'stdout' => '', 'stderr' => 'Could not create process output storage.'];
            }

            $descriptors = [STDIN, $output, $output];
        }

        $pipes = [];
        $process = proc_open([$command, ...$arguments], $descriptors, $pipes, null, $options['env'] ?? null, [
            'bypass_shell' => true,
        ]);

        if (! is_resource($process)) {
            if (is_resource($output)) {
                fclose($output);
            }

            return ['status' => 1, 'stdout' => '', 'stderr' => 'The process could not start.'];
        }

        $status = proc_close($process);
        $stdout = '';

        if (is_resource($output)) {
            rewind($output);
            $stdout = (string) stream_get_contents($output);
            fclose($output);
        }

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => ''];
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException('Could not inspect temporary directory: '.$directory);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                self::removeDirectory($path);
            } elseif (! unlink($path)) {
                throw new RuntimeException('Could not remove temporary file: '.$path);
            }
        }

        if (! rmdir($directory)) {
            throw new RuntimeException('Could not remove temporary directory: '.$directory);
        }
    }
}
