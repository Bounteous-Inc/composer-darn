<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\DarnCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestDarnCommand extends DarnCommand
{
    public $composerMock;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('test:darn');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function testGetPatchesFromDisk(?string $packageName = null): array
    {
        return $this->getPatchesFromDisk($packageName);
    }

    public function testGetPatchesDirectory(?string $override = null): string
    {
        return $this->getPatchesDirectory($override);
    }

    // Override requireComposer to return our mock
    public function requireComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): Composer
    {
        return $this->composerMock ?? parent::requireComposer($disablePlugins, $disableScripts);
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_cmd_test_'.uniqid();
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir);
    }
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');

    // Mock Composer environment
    $this->composer = $this->createMock(Composer::class);
    $this->package = $this->createMock(RootPackageInterface::class);
    $this->package->method('getExtra')->willReturn([]);
    $this->composer->method('getPackage')->willReturn($this->package);

    // Mock IO
    $this->io = $this->createMock(IOInterface::class);

    $this->command = new TestDarnCommand();
    $this->command->setComposer($this->composer);
    $this->command->composerMock = $this->composer;
    $this->application = $this->createMock(Application::class);
    $this->command->setApplication($this->application);
    $this->command->setIO($this->io);

    $this->tester = new CommandTester($this->command);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalCwd);
    if (file_exists($this->tempComposerJson)) {
        unlink($this->tempComposerJson);
    }
    if (is_dir($this->tempDir)) {
        // Recursive delete
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

it('has dir option', function () {
    $definition = $this->command->getDefinition();
    expect($definition->hasOption('dir'))->toBeTrue();
});

it('scans patches from disk', function () {
    // Setup patches directory structure
    $patchDir = 'patches/drupal/core';
    if (! is_dir($patchDir)) {
        mkdir($patchDir, 0777, true);
    }
    file_put_contents("$patchDir/test.patch", 'content');

    // Execute command to initialize input
    $this->tester->execute([]);

    $files = $this->command->testGetPatchesFromDisk();
    expect($files)->toBe(['patches/drupal/core/test.patch']);
});

it('returns empty array if patches directory does not exist', function () {
    $this->tester->execute([]);
    $files = $this->command->testGetPatchesFromDisk();
    expect($files)->toBeEmpty();
});

it('scans patches from disk filtered by package name', function () {
    $coreDir = 'patches/drupal/core';
    $viewsDir = 'patches/drupal/views';
    if (! is_dir($coreDir)) {
        mkdir($coreDir, 0777, true);
    }
    if (! is_dir($viewsDir)) {
        mkdir($viewsDir, 0777, true);
    }
    file_put_contents("$coreDir/core.patch", 'core content');
    file_put_contents("$viewsDir/views.patch", 'views content');

    $this->tester->execute([]);

    $files = $this->command->testGetPatchesFromDisk('drupal/core');

    expect($files)->toBe(['patches/drupal/core/core.patch']);
});

it('returns empty array when filtered package directory does not exist', function () {
    $this->tester->execute([]);
    $files = $this->command->testGetPatchesFromDisk('drupal/nonexistent');
    expect($files)->toBeEmpty();
});

it('returns patches directory from composer config', function () {
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn(['composer-darn' => ['patches-dir' => 'custom/patches']]);
    $composer = $this->createMock(Composer::class);
    $composer->method('getPackage')->willReturn($package);
    $this->command->composerMock = $composer;

    expect($this->command->testGetPatchesDirectory())->toBe('custom/patches');
});

it('returns default patches directory when config key is absent', function () {
    expect($this->command->testGetPatchesDirectory())->toBe('patches');
});

it('returns override directory when one is provided', function () {
    expect($this->command->testGetPatchesDirectory('cli/dir'))->toBe('cli/dir');
});
