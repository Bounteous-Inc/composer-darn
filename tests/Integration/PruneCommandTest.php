<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\PruneCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestPruneCommand extends PruneCommand
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
    $this->tempDir = sys_get_temp_dir().'/darn_prune_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->command = new TestPruneCommand();
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
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $fileinfo) {
            @($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
        }
        @rmdir($this->tempDir);
    }
});

it('identifies orphaned files and missing config', function () {
    // 1. Setup composer.json with a missing file reference
    $json = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Missing Patch' => 'patches/missing.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    // 2. Setup patches directory with an orphaned file
    if (! is_dir('patches')) {
        mkdir('patches');
    }
    file_put_contents('patches/orphan.patch', 'content');

    // 3. Mock IO to confirm deletion
    $this->io->method('write');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(true); // Confirm delete orphan

    // Execute
    $this->tester->execute([]);

    // Assert orphan deleted
    expect(file_exists('patches/orphan.patch'))->toBeFalse();

    // Assert missing config still there (because --clean-config not passed)
    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }
    $json = json_decode($content, true);
    expect($json['extra']['patches']['drupal/core'])->toHaveKey('Missing Patch');
});

it('cleans config when requested', function () {
    // 1. Setup composer.json with a missing file reference
    $json = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Missing Patch' => 'patches/missing.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    // 2. Mock IO
    $this->io->method('write');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(true); // Confirm clean config

    // Execute with --clean-config
    $this->tester->execute(['--clean-config' => true]);

    // Assert config cleaned
    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }
    $json = json_decode($content, true);
    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
});

it('fails gracefully when composer.json is invalid', function () {
    file_put_contents($this->tempComposerJson, '{invalid');

    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->stringContains('does not contain valid JSON'));

    $statusCode = $this->tester->execute([]);
    expect($statusCode)->toBe(1);
});

it('reports nothing to prune when all patches are tracked and no orphans exist', function () {
    if (! is_dir('patches')) {
        mkdir('patches', 0777, true);
    }
    file_put_contents('patches/tracked.patch', 'content');

    $json = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Tracked Patch' => 'patches/tracked.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    $this->io->method('write');
    $this->io->expects($this->never())->method('askConfirmation');

    $statusCode = $this->tester->execute([]);
    expect($statusCode)->toBe(0);
});

it('preserves orphaned files when user declines deletion', function () {
    if (! is_dir('patches')) {
        mkdir('patches');
    }
    file_put_contents('patches/orphan.patch', 'orphan content');

    $this->io->method('write');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(false);

    $this->tester->execute([]);

    expect(file_exists('patches/orphan.patch'))->toBeTrue();
});

it('recognises modern array-format entries and does not flag them as orphans', function () {
    $json = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Modern Format Patch', 'url' => 'patches/modern.patch'],
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    if (! is_dir('patches')) {
        mkdir('patches');
    }
    file_put_contents('patches/modern.patch', 'content');

    $this->io->method('write');
    $this->io->expects($this->never())->method('askConfirmation');

    $statusCode = $this->tester->execute([]);
    expect($statusCode)->toBe(0);
});

it('prunes only specified package', function () {
    // 1. Setup composer.json
    $json = [
        'extra' => [
            'patches' => [
                'drupal/core' => [],
                'drupal/other' => [],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    // 2. Setup patches directory with orphans in both packages
    if (! is_dir('patches/drupal/core')) {
        mkdir('patches/drupal/core', 0777, true);
    }
    if (! is_dir('patches/drupal/other')) {
        mkdir('patches/drupal/other', 0777, true);
    }

    file_put_contents('patches/drupal/core/orphan.patch', 'content');
    file_put_contents('patches/drupal/other/orphan.patch', 'content');

    // 3. Mock IO
    $this->io->method('write');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

    // Execute with package argument
    $this->tester->execute(['package' => 'drupal/core']);

    // Assert core orphan deleted
    expect(file_exists('patches/drupal/core/orphan.patch'))->toBeFalse();

    // Assert other orphan still exists
    expect(file_exists('patches/drupal/other/orphan.patch'))->toBeTrue();
});
