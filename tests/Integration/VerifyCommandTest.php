<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\VerifyCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestVerifyCommand extends VerifyCommand
{
    public IOInterface $ioMock;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Bypass Composer\Command\BaseCommand::initialize() which would eagerly
        // parse composer.json, throwing before execute() can handle error conditions.
        $this->input = $input;
        $this->output = $output;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_test_'.uniqid();
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir);
    }
    $this->tempComposerJson = $this->tempDir.'/composer.json';

    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->command = new TestVerifyCommand();
    $this->command->ioMock = $this->io;
    $this->command->setApplication(new Application());
    $this->sf = new TestServiceFactory($this->io);
    $this->command->setServiceFactory($this->sf);

    $this->tester = new CommandTester($this->command);
});

afterEach(function () {
    if (file_exists($this->tempComposerJson)) {
        unlink($this->tempComposerJson);
    }

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($this->tempDir);

    putenv('COMPOSER'); // Unset
});

it('verifies existing patches correctly', function () {
    $patches = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Test Patch' => 'patches/test.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $patchDir = $this->tempDir.'/patches';
    if (! is_dir($patchDir)) {
        mkdir($patchDir);
    }
    file_put_contents($patchDir.'/test.patch', 'patch content');

    $this->io->expects($this->exactly(2))
        ->method('write')
        ->with($this->callback(function ($arg) {
            return preg_match('/Verifying patches/', $arg) || preg_match('/Verification successful/', $arg);
        }));

    $this->io->expects($this->never())
        ->method('writeError');

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('fails when patches are missing', function () {
    $patches = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Missing Patch' => 'patches/missing.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $this->io->expects($this->once())
        ->method('write')
        ->with($this->matchesRegularExpression('/Verifying patches/'));

    $this->io->expects($this->exactly(2))
        ->method('writeError')
        ->with($this->logicalOr($this->matchesRegularExpression('/Missing patch/'), $this->matchesRegularExpression('/Verification failed/')));

    $result = $this->tester->execute([]);

    expect($result)->toBe(1);
});

it('handles composer.json without extra key', function () {
    $patches = [
        'name' => 'test/project',
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $this->io->expects($this->once())
        ->method('write')
        ->with($this->matchesRegularExpression('/No patches found/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('calls prune command when --prune option is used', function () {
    $pruneCommand = $this->createMock(Command::class);
    $pruneCommand->expects($this->once())
        ->method('run')
        ->willReturn(0);

    $application = $this->createMock(Application::class);
    $application->method('find')->with('darn:prune')->willReturn($pruneCommand);
    $application->method('getHelperSet')->willReturn(new HelperSet());
    $application->method('getDefinition')->willReturn(new InputDefinition());

    $this->command->setApplication($application);

    $result = $this->tester->execute(['--prune' => true]);

    expect($result)->toBe(0);
});

it('passes when no patches defined', function () {
    $patches = [
        'extra' => [],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $this->io->expects($this->once())
        ->method('write')
        ->with($this->matchesRegularExpression('/No patches found/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('fails when composer.json is missing', function () {
    // beforeEach creates the directory but not the file — running without creating
    // the file simulates a missing composer.json.

    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/composer.json not found/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(1);
});

it('warns about duplicate patch URLs across packages', function () {
    $patches = [
        'extra' => [
            'patches' => [
                'drupal/core' => ['Patch A' => 'patches/shared.patch'],
                'drupal/views' => ['Patch B' => 'patches/shared.patch'],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $patchDir = $this->tempDir.'/patches';
    if (! is_dir($patchDir)) {
        mkdir($patchDir);
    }
    file_put_contents($patchDir.'/shared.patch', 'content');

    $sawDuplicateWarning = false;
    $this->io->method('write');
    $this->io->expects($this->any())->method('writeError')
        ->willReturnCallback(function ($message) use (&$sawDuplicateWarning) {
            if (preg_match('/[Dd]uplicate.*shared\.patch/', $message)) {
                $sawDuplicateWarning = true;
            }
        });

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
    expect($sawDuplicateWarning)->toBeTrue();
});

it('warns when patches directory is listed in .gitignore', function () {
    $patches = [
        'extra' => [
            'patches' => [
                'drupal/core' => ['Test Patch' => 'patches/test.patch'],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $patchDir = $this->tempDir.'/patches';
    if (! is_dir($patchDir)) {
        mkdir($patchDir);
    }
    file_put_contents($patchDir.'/test.patch', 'content');

    // Simulate a git repository with an ignoring .gitignore
    mkdir($this->tempDir.'/.git');
    file_put_contents($this->tempDir.'/.gitignore', "vendor/\npatches/\n");

    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())->method('writeError')
        ->with($this->matchesRegularExpression('/gitignored/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('does not warn about gitignore when patches dir is not ignored', function () {
    $patches = [
        'extra' => [
            'patches' => [
                'drupal/core' => ['Test Patch' => 'patches/test.patch'],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($patches));

    $patchDir = $this->tempDir.'/patches';
    if (! is_dir($patchDir)) {
        mkdir($patchDir);
    }
    file_put_contents($patchDir.'/test.patch', 'content');

    // Simulate a git repo whose .gitignore does not mention patches/
    mkdir($this->tempDir.'/.git');
    file_put_contents($this->tempDir.'/.gitignore', "vendor/\n");

    $this->io->expects($this->exactly(2))
        ->method('write')
        ->with($this->callback(function ($arg) {
            return preg_match('/Verifying patches/', $arg) || preg_match('/Verification successful/', $arg);
        }));
    $this->io->expects($this->never())->method('writeError');

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('fails when composer.json is invalid', function () {
    file_put_contents($this->tempComposerJson, '{invalid');

    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Failed to parse composer.json/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(1);
});
