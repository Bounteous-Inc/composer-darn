<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\GithubCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Minimal test double: only overrides IO resolution and patches-directory so
 * tests don't need a real Composer environment.  All service injection happens
 * through the public setServiceFactory() method inherited from DarnCommand.
 */
class TestGithubCommand extends GithubCommand
{
    public IOInterface $ioMock;

    /** Fixed patches directory returned by getPatchesDirectory() in tests. */
    public string $overridePatchesDir = 'patches';

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    /**
     * Returns the --dir option value if given, otherwise $overridePatchesDir.
     */
    protected function getPatchesDirectory(?string $override = null): string
    {
        return $override ?? $this->overridePatchesDir;
    }

    protected function isPatchPackageInstalled(): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_gh_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->patchesDir = $this->tempDir.'/patches';
    mkdir($this->patchesDir);
    $this->composerJsonPath = $this->tempDir.'/composer.json';
    file_put_contents($this->composerJsonPath, '{}');

    putenv('COMPOSER='.$this->composerJsonPath);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);

    $this->io = $this->createMock(IOInterface::class);

    $this->sf = makeTestSf($this->io);

    $this->command = new TestGithubCommand();
    $this->command->ioMock = $this->io;
    $this->command->overridePatchesDir = 'patches';
    $this->command->setApplication(new Application());
    $this->command->setServiceFactory($this->sf);

    $this->tester = new CommandTester($this->command);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

it('downloads a patch from github and detects package name', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        new Response(200, [], json_encode(['name' => 'my/package'])),
        new Response(200, [], 'PATCH_CONTENT'),
        new Response(200, [], json_encode(['title' => 'Fix render pipeline', 'number' => 123])),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');

    $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
    ], ['interactive' => false]);

    $expectedFile = $this->patchesDir.'/my/package/user-repo-pr-123.patch';
    expect(file_exists($expectedFile))->toBeTrue();
    expect(file_get_contents($expectedFile))->toBe('PATCH_CONTENT');

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patches = $json['extra']['patches']['my/package'] ?? [];
    expect($patches)->not->toBeEmpty();
    expect($patches[0]['extra']['issue-tracker-url'])->toBe('https://github.com/user/repo/pull/123');
    expect($patches[0]['description'])->toBe('PR #123: Fix render pipeline (user/repo)');
});

it('fails if package name cannot be detected and not provided', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(404, [], ''),
        new Response(404, [], ''),
        new Response(404, [], ''),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Package name is required/'));

    $statusCode = $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
    ], ['interactive' => false]);

    expect($statusCode)->toBe(1);
});

it('sends Authorization header when GITHUB_TOKEN is set', function () {
    $token = 'test_token_123';
    putenv("GITHUB_TOKEN=$token");

    $history = [];
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        new Response(200, [], json_encode(['name' => 'my/package'])),
        new Response(200, [], 'PATCH_CONTENT'),
        new Response(200, [], json_encode(['title' => 'Fix render pipeline', 'number' => 123])),
    ], $history));

    $this->io->method('write');
    $this->io->method('writeError');

    $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
    ], ['interactive' => false]);

    putenv('GITHUB_TOKEN');

    expect($history)->not->toBeEmpty();
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe("token $token");
});

it('fails when patch download fails', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(404, [], 'Not Found'),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Failed to download patch/'));

    $statusCode = $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
        'package' => 'my/package',
    ], ['interactive' => false]);

    expect($statusCode)->toBe(1);
});

it('fails gracefully when GitHub API is rate limited during package detection', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(403, ['X-RateLimit-Remaining' => '0'], 'Rate Limit Exceeded'),
        new Response(403, [], 'Forbidden'),
        new Response(403, [], 'Forbidden'),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Package name is required/'));

    $statusCode = $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
    ], ['interactive' => false]);

    expect($statusCode)->toBe(1);
});

it('rejects a URL that is neither a GitHub PR nor a .diff/.patch file', function () {
    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with('<error>The URL must be a GitHub Pull Request or end in .diff/.patch</error>');

    $statusCode = $this->tester->execute([
        'url' => 'https://github.com/user/repo',
    ], ['interactive' => false]);

    expect($statusCode)->toBe(1);
});

it('downloads a direct .diff URL without appending .diff', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'DIFF_CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');

    $this->tester->execute([
        'url' => 'https://example.com/fix.diff',
        'package' => 'my/package',
    ], ['interactive' => false]);

    $expectedFile = $this->patchesDir.'/my/package/fix.diff';
    expect(file_exists($expectedFile))->toBeTrue();
    expect(file_get_contents($expectedFile))->toBe('DIFF_CONTENT');
});

it('strips query string from filename when PR URL contains query parameters', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        new Response(200, [], json_encode(['name' => 'my/package'])),
        new Response(200, [], 'PATCH_CONTENT'),
        new Response(200, [], json_encode(['title' => 'Fix bug', 'number' => 123])),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');

    // URL with query string — filename derived from owner/repo/PR number, ignoring query string
    $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123?tab=files',
    ], ['interactive' => false]);

    $expectedFile = $this->patchesDir.'/my/package/user-repo-pr-123.patch';
    expect(file_exists($expectedFile))->toBeTrue();

    // Query string must be stripped from the stored issue-tracker-url.
    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patches = $json['extra']['patches']['my/package'] ?? [];
    expect($patches[0]['extra']['issue-tracker-url'])->toBe('https://github.com/user/repo/pull/123');
});

it('uses --description option for the patch description', function () {
    // With --description set, the PR title API call is skipped (only 3 responses needed).
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        new Response(200, [], json_encode(['name' => 'my/package'])),
        new Response(200, [], 'PATCH_CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');

    $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
        '--description' => 'Fix critical bug in render pipeline',
    ], ['interactive' => false]);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patches = $json['extra']['patches']['my/package'] ?? [];
    expect($patches[0]['description'])->toBe('Fix critical bug in render pipeline');
    expect(file_exists($this->patchesDir.'/my/package/user-repo-pr-123.patch'))->toBeTrue();
});

it('prompts for description interactively when --description is not given', function () {
    // Package provided explicitly → no package detection. Download + PR API = 2 responses.
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'PATCH_CONTENT'),
        new Response(200, [], json_encode(['title' => 'Fix render pipeline', 'number' => 123])),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');
    // User overrides the PR-title default with a custom description.
    $this->io->method('ask')->willReturn('Custom interactive description');

    $this->tester->execute([
        'url' => 'https://github.com/user/repo/pull/123',
        'package' => 'my/package',
    ], ['interactive' => true]);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patches = $json['extra']['patches']['my/package'] ?? [];
    expect($patches[0]['description'])->toBe('Custom interactive description');
});
