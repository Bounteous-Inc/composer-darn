<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Bounteous\Darn\Command\DrupalOrgPatchCommand;
use Bounteous\Darn\Command\FixCommand;
use Bounteous\Darn\Command\GithubCommand;
use Bounteous\Darn\Command\PruneCommand;
use Bounteous\Darn\Command\VerifyCommand;
use Bounteous\Darn\Patch\PatchValidator;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

trait TestCommandTrait
{
    /** Guzzle client injected by setClient() before execute() is called. */
    private ?Client $testGuzzleClient = null;

    /**
     * Keeps the setClient() call-site API that E2E tests use, while routing
     * the client through TestServiceFactory instead of the removed trait.
     */
    public function setClient(Client $client): void
    {
        $this->testGuzzleClient = $client;
    }

    protected function isPatchPackageInstalled(): bool
    {
        return true;
    }

    /**
     * Simulate a Composer install by writing the expected output, without actually
     * resolving or downloading packages (which would require a real vendor tree and
     * an internet connection, causing both a 25-second timeout and a PHP warning about
     * the missing vendor/composer/installed.php file).
     */
    protected function triggerComposerInstall(IOInterface $io): void
    {
        $io->write('<info>Applying patches...</info>');
    }

    /**
     * Override run to link the Composer IO to the Symfony streams right as the
     * command starts, and to inject a TestServiceFactory with a passthrough
     * PatchValidator (and optional Guzzle client) before execute() runs.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $helperSet = $this->getHelperSet();
        if ($helperSet === null) {
            throw new \RuntimeException('HelperSet is null');
        }

        $this->setIO(new ConsoleIO($input, $output, $helperSet));

        $tf = new TestServiceFactory($this->getIO());
        $tf->setPatchValidator(new class () extends PatchValidator {
            public function validate(string $filepath, string $packageName, Composer $composer, ?int $depth, IOInterface $io): bool
            {
                return true;
            }
        });

        if ($this->testGuzzleClient !== null) {
            $tf->setGuzzleClient($this->testGuzzleClient);
        }

        $this->setServiceFactory($tf);

        return parent::run($input, $output);
    }
}

class TestDrupalOrgPatchCommand extends DrupalOrgPatchCommand
{
    use TestCommandTrait;
}

class TestPruneCommand extends PruneCommand
{
    use TestCommandTrait;
}

class TestVerifyCommand extends VerifyCommand
{
    use TestCommandTrait;
}

class TestGithubCommand extends GithubCommand
{
    use TestCommandTrait;
}

class TestFixCommand extends FixCommand
{
    use TestCommandTrait;
}

function setupApplication(): Application
{
    $application = new Application();
    $application->addCommand(new TestDrupalOrgPatchCommand());
    $application->addCommand(new TestPruneCommand());
    $application->addCommand(new TestVerifyCommand());
    $application->addCommand(new TestGithubCommand());
    $application->addCommand(new TestFixCommand());

    return $application;
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_e2e_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->composerJsonPath = $this->tempDir.'/composer.json';

    // Create a basic composer.json
    file_put_contents($this->composerJsonPath, json_encode([
        'name' => 'test/project',
        'require' => [
            'drupal/core' => '^10.0',
        ],
        'extra' => [
            'patches' => [],
        ],
    ], JSON_PRETTY_PRINT));

    // Isolate Composer environment
    putenv('COMPOSER='.$this->composerJsonPath);

    // Switch to temp dir to ensure relative paths work as expected
    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');

    if (is_dir($this->tempDir)) {
        chmod($this->tempDir, 0777);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $path = $fileinfo->getRealPath();
            // Ensure the parent directory of the file is writable so we can unlink
            if (! $fileinfo->isDir()) {
                chmod(dirname($path), 0777);
            }

            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($path); // Use @ to suppress remaining edge-case OS warnings
        }
        rmdir($this->tempDir);
    }
});

it('fetches patch from drupal.org and updates composer.json', function () {
    $application = setupApplication();
    $command = $application->find('darn:drupal.org');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    // Mock Guzzle responses
    $mock = new MockHandler([
        // Issue details
        new Response(200, [], json_encode([
            'title' => 'Test Issue',
            'field_issue_status' => '1',
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [['file' => ['id' => '100', 'cid' => '1']]],
            'comments' => [['id' => '1']],
        ], JSON_THROW_ON_ERROR)),
        // MR Search (empty)
        new Response(200, [], '[]'),
        // File details
        new Response(200, [], json_encode([
            'name' => 'test.patch',
            'url' => 'http://example.com/test.patch',
            'filesize' => 1024,
            'timestamp' => time(),
        ], JSON_THROW_ON_ERROR)),
        // Patch content download
        new Response(200, [], 'PATCH CONTENT'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    if ($command instanceof DrupalOrgPatchCommand) {
        $command->setClient($client);
    }

    $tester = new CommandTester($command);
    $tester->setInputs(['0']); // Select the first patch

    $tester->execute(['issue_id' => '12345']);

    $output = $tester->getDisplay();
    expect($output)->toContain('Downloading patch');
    expect($output)->toContain('Updated composer.json');

    // Assert patch file exists
    $patchPath = $this->tempDir.'/patches/drupal/core/12345-1-test.patch';
    expect(file_exists($patchPath))->toBeTrue();
    expect(file_get_contents($patchPath))->toBe('PATCH CONTENT');

    // Assert composer.json updated
    $content = file_get_contents($this->composerJsonPath);
    if ($content === false) {
        throw new \RuntimeException("Failed to read composer.json at: {$this->composerJsonPath}");
    }

    $json = json_decode($content, true);
    $patches = $json['extra']['patches']['drupal/core'];
    $foundPatch = null;
    foreach ($patches as $patch) {
        if (($patch['description'] ?? '') === 'Issue #12345: Test Issue (test.patch)') {
            $foundPatch = $patch;
            break;
        }
    }
    expect($foundPatch['url'])->toBe('patches/drupal/core/12345-1-test.patch');
});

it('attempts to apply patches when --apply flag is used', function () {
    $application = setupApplication();
    $command = $application->find('darn:drupal.org');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    // Reuse mock setup (simplified)
    $mock = new MockHandler([
        new Response(200, [], json_encode(['title' => 'Issue', 'field_project' => ['machine_name' => 'drupal'], 'field_issue_files' => [['file' => ['id' => '100']]], 'comments' => [['id' => '1']]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode(['name' => 'test.patch', 'url' => 'http://example.com/test.patch', 'filesize' => 1024, 'timestamp' => time()], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'CONTENT'),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    if ($command instanceof DrupalOrgPatchCommand) {
        $command->setClient($client);
    }

    $tester = new CommandTester($command);
    $tester->setInputs(['0']);

    $tester->execute(['issue_id' => '12345', '--apply' => true]);

    $output = $tester->getDisplay();
    // We expect the command to try running composer install
    expect($output)->toContain('Applying patches...');
});

it('prunes orphan files', function () {
    $application = setupApplication();
    $command = $application->find('darn:prune');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    // Seed orphan file
    $orphanDir = $this->tempDir.'/patches/drupal/core';
    mkdir($orphanDir, 0777, true);
    file_put_contents($orphanDir.'/orphan.patch', 'orphan content');

    $tester = new CommandTester($command);
    $tester->setInputs(['yes']); // Confirm deletion

    $tester->execute([]);

    $output = $tester->getDisplay();
    expect($output)->toContain('Deleted: patches/drupal/core/orphan.patch');
    expect(file_exists($orphanDir.'/orphan.patch'))->toBeFalse();
});

it('fetches patch from github and updates composer.json', function () {
    $application = setupApplication();
    $command = $application->find('darn:github');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    // 4 HTTP interactions in order:
    // 1. GitHub REST API → default_branch
    // 2. raw.githubusercontent.com → composer.json (package name)
    // 3. github.com → patch download
    // 4. GitHub REST API → PR title for description
    $mock = new MockHandler([
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        new Response(200, [], json_encode(['name' => 'drupal/core'])),
        new Response(200, [], 'GITHUB PATCH CONTENT'),
        new Response(200, [], json_encode(['title' => 'Fix render pipeline', 'number' => 42])),
    ]);

    if ($command instanceof GithubCommand) {
        $command->setClient(new Client(['handler' => HandlerStack::create($mock)]));
    }

    $tester = new CommandTester($command);
    $tester->execute(
        ['url' => 'https://github.com/user/repo/pull/42'],
        ['interactive' => false]
    );

    $output = $tester->getDisplay();
    expect($output)->toContain('Saved patch to');
    expect($output)->toContain('Updated composer.json');

    $patchPath = $this->tempDir.'/patches/drupal/core/user-repo-pr-42.patch';
    expect(file_exists($patchPath))->toBeTrue();
    expect(file_get_contents($patchPath))->toBe('GITHUB PATCH CONTENT');

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['drupal/core'] ?? [])->not->toBeEmpty();
});

it('normalizes existing patches with darn:fix', function () {
    $application = setupApplication();
    $command = $application->find('darn:fix');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    $packageName = 'some/package';

    file_put_contents($this->composerJsonPath, json_encode([
        'name' => 'test/project',
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old PR patch', 'url' => 'https://github.com/acme/widget/pull/99.patch'],
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT));

    $mock = new MockHandler([
        new Response(200, [], json_encode(['title' => 'Add new feature', 'number' => 99])),
        new Response(200, [], 'PATCH CONTENT'),
    ]);

    if ($command instanceof FixCommand) {
        $command->setClient(new Client(['handler' => HandlerStack::create($mock)]));
    }

    $tester = new CommandTester($command);
    $tester->execute([]);

    $output = $tester->getDisplay();
    expect($output)->toContain('1 updated');

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches'][$packageName][0];
    expect($patch['description'])->toBe('PR #99: Add new feature (acme/widget)');
    expect($patch['url'])->toBe("patches/{$packageName}/acme-widget-pr-99.patch");
    expect(file_exists($this->tempDir."/patches/{$packageName}/acme-widget-pr-99.patch"))->toBeTrue();
});

it('verifies configuration resilience', function () {
    $application = setupApplication();
    $command = $application->find('darn:verify');

    $composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $composer->method('getPackage')->willReturn($package);
    $command->setComposer($composer);

    // Update composer.json with a missing patch
    $content = file_get_contents($this->composerJsonPath);
    if ($content === false) {
        throw new \RuntimeException("Failed to read composer.json at: {$this->composerJsonPath}");
    }

    $json = json_decode($content, true);
    $json['extra']['patches']['drupal/core']['Missing Patch'] = 'patches/missing.patch';
    file_put_contents($this->composerJsonPath, json_encode($json));

    $tester = new CommandTester($command);
    $statusCode = $tester->execute([]);

    expect($statusCode)->not->toBe(0);
    $output = $tester->getDisplay();
    expect($output)->toContain('Missing patch for drupal/core: Missing Patch (patches/missing.patch)');
});
