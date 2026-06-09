<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\DispatchCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestDispatchCommand extends DispatchCommand
{
    public IOInterface $ioMock;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Bypass Composer\Command\BaseCommand::initialize() to avoid eager
        // composer.json parsing in tests.
        $this->input = $input;
        $this->output = $output;
    }
}

// ---------------------------------------------------------------------------
// Helper: create a mock Application that routes find() to a stub command.
// ---------------------------------------------------------------------------

/**
 * Configures a pre-built mock Application to route find() to the given delegate.
 *
 * The caller must create the mock (via $this->createMock()) and pass it here,
 * because createMock() is a protected TestCase method that cannot be called
 * from global-function scope.
 *
 * @param  MockObject  $app  Pre-built Application mock.
 * @param  string  $expectedCommandName  The command name asserted via find().
 * @param  MockObject  $delegate  Pre-built stub that will be returned.
 */
function configureRoutingApplication(object $app, string $expectedCommandName, object $delegate): void
{
    $app->method('find')->with($expectedCommandName)->willReturn($delegate);
    $app->method('getHelperSet')->willReturn(new HelperSet());
    $app->method('getDefinition')->willReturn(new InputDefinition());
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_dispatch_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->command = new TestDispatchCommand();
    $this->command->ioMock = $this->io;
    $this->command->setApplication(new Application());

    $this->sf = new TestServiceFactory($this->io);
    $this->command->setServiceFactory($this->sf);

    $this->tester = new CommandTester($this->command);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');

    if (is_dir($this->tempDir)) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            @($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
        }
        @rmdir($this->tempDir);
    }
});

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

it('routes a drupal.org /node/ URL to darn:drupal.org', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:drupal.org', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://www.drupal.org/node/12345']);

    expect($result)->toBe(0);
});

it('routes a drupal.org /issues/ URL to darn:drupal.org', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:drupal.org', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://www.drupal.org/project/views/issues/3456789']);

    expect($result)->toBe(0);
});

it('routes a github.com PR URL to darn:github', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:github', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://github.com/owner/repo/pull/42']);

    expect($result)->toBe(0);
});

it('routes a .patch URL to darn:patch', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://www.drupal.org/files/issues/fix.patch']);

    expect($result)->toBe(0);
});

it('routes a .diff URL to darn:patch', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://git.drupalcode.org/project/module/-/merge_requests/5.diff']);

    expect($result)->toBe(0);
});

it('routes a local .patch file path to darn:patch', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->expects($this->once())->method('run')->willReturn(0);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'patches/drupal/core/my-fix.patch']);

    expect($result)->toBe(0);
});

it('returns the delegate exit code', function () {
    $delegate = $this->createMock(Command::class);
    $delegate->method('run')->willReturn(42);

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:github', $delegate);
    $this->command->setApplication($app);

    $result = $this->tester->execute(['source' => 'https://github.com/owner/repo/pull/1']);

    expect($result)->toBe(42);
});

it('errors on an unrecognised source', function () {
    $this->io->method('writeError');

    $result = $this->tester->execute(['source' => 'https://example.com/some/page']);

    expect($result)->toBe(1);
});

// ---------------------------------------------------------------------------
// Option forwarding
// ---------------------------------------------------------------------------

it('forwards --package to darn:github as a positional argument', function () {
    $capturedInput = null;
    $delegate = $this->createMock(Command::class);
    $delegate->method('run')->willReturnCallback(function ($input) use (&$capturedInput) {
        $capturedInput = $input;

        return 0;
    });

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:github', $delegate);
    $this->command->setApplication($app);

    $this->tester->execute([
        'source' => 'https://github.com/owner/repo/pull/1',
        '--package' => 'drupal/core',
    ]);

    // GithubCommand accepts package as a positional argument; the dispatch
    // forwards it under the 'package' key (not '--package').
    expect($capturedInput->getParameterOption('package'))->toBe('drupal/core');
});

it('forwards --package to darn:patch as an option', function () {
    $capturedInput = null;
    $delegate = $this->createMock(Command::class);
    $delegate->method('run')->willReturnCallback(function ($input) use (&$capturedInput) {
        $capturedInput = $input;

        return 0;
    });

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
    ]);

    expect($capturedInput->getParameterOption('--package'))->toBe('drupal/core');
});

it('forwards --description to the delegate command', function () {
    $capturedInput = null;
    $delegate = $this->createMock(Command::class);
    $delegate->method('run')->willReturnCallback(function ($input) use (&$capturedInput) {
        $capturedInput = $input;

        return 0;
    });

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
        '--description' => 'My patch description',
    ]);

    expect($capturedInput->getParameterOption('--description'))->toBe('My patch description');
});

it('forwards --ticket to the delegate command', function () {
    $capturedInput = null;
    $delegate = $this->createMock(Command::class);
    $delegate->method('run')->willReturnCallback(function ($input) use (&$capturedInput) {
        $capturedInput = $input;

        return 0;
    });

    $app = $this->createMock(Application::class);
    configureRoutingApplication($app, 'darn:patch', $delegate);
    $this->command->setApplication($app);

    $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
        '--ticket' => 'JIRA-123',
    ]);

    expect($capturedInput->getParameterOption('--ticket'))->toBe('JIRA-123');
});
