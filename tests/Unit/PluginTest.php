<?php

declare(strict_types=1);

use Bounteous\Darn\Plugin;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Overrides runFixCommand() so tests can assert it was (or was not) invoked
 * without executing FixCommand's HTTP-dependent logic.
 */
class TestPlugin extends Plugin
{
    public int $runFixCommandCalls = 0;

    protected function runFixCommand(): void
    {
        $this->runFixCommandCalls++;
    }
}

beforeEach(function () {
    $this->tempJson = null;

    $this->package = $this->createMock(PackageInterface::class);
    $this->package->method('getName')->willReturn('bounteous-inc/composer-darn');

    $this->operation = $this->createMock(InstallOperation::class);
    $this->operation->method('getPackage')->willReturn($this->package);

    $this->event = $this->createMock(PackageEvent::class);
    $this->event->method('getOperation')->willReturn($this->operation);

    $this->composer = $this->createMock(Composer::class);
});

afterEach(function () {
    if ($this->tempJson !== null && file_exists($this->tempJson)) {
        unlink($this->tempJson);
    }
    putenv('COMPOSER');
});

// --- interface / capability tests ---

it('implements plugin interface', function () {
    $plugin = new Plugin();
    expect($plugin)->toBeInstanceOf(PluginInterface::class);
    expect($plugin)->toBeInstanceOf(Capable::class);
    expect($plugin)->toBeInstanceOf(EventSubscriberInterface::class);
});

it('provides command provider capability', function () {
    $plugin = new Plugin();
    $capabilities = $plugin->getCapabilities();

    expect($capabilities)->toHaveKey(CommandProvider::class);
    expect($capabilities[CommandProvider::class])->toBe(Bounteous\Darn\CommandProvider::class);
});

it('activates and deactivates without errors', function () {
    $plugin = new Plugin();
    $io = $this->createMock(IOInterface::class);

    $this->expectNotToPerformAssertions();

    $plugin->activate($this->composer, $io);
    $plugin->deactivate($this->composer, $io);
    $plugin->uninstall($this->composer, $io);
});

it('subscribes to POST_PACKAGE_INSTALL', function () {
    $events = Plugin::getSubscribedEvents();

    expect($events)->toHaveKey(PackageEvents::POST_PACKAGE_INSTALL);
    expect($events[PackageEvents::POST_PACKAGE_INSTALL])->toBe('onPostPackageInstall');
});

// --- onPostPackageInstall behaviour ---

it('does nothing when a different package is installed', function () {
    $this->package->method('getName')->willReturn('some/other-package');

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->never())->method('askConfirmation');

    $plugin = new TestPlugin();
    $plugin->activate($this->composer, $io);
    $plugin->onPostPackageInstall($this->event);

    expect($plugin->runFixCommandCalls)->toBe(0);
});

it('does nothing in non-interactive mode', function () {
    $io = $this->createMock(IOInterface::class);
    $io->method('isInteractive')->willReturn(false);
    $io->expects($this->never())->method('askConfirmation');

    $plugin = new TestPlugin();
    $plugin->activate($this->composer, $io);
    $plugin->onPostPackageInstall($this->event);

    expect($plugin->runFixCommandCalls)->toBe(0);
});

it('does nothing when composer.json has no patches', function () {
    $this->tempJson = tempnam(sys_get_temp_dir(), 'darn_plugin_');
    file_put_contents($this->tempJson, json_encode(['name' => 'test/project']));
    putenv('COMPOSER='.$this->tempJson);

    $io = $this->createMock(IOInterface::class);
    $io->method('isInteractive')->willReturn(true);
    $io->expects($this->never())->method('askConfirmation');

    $plugin = new TestPlugin();
    $plugin->activate($this->composer, $io);
    $plugin->onPostPackageInstall($this->event);

    expect($plugin->runFixCommandCalls)->toBe(0);
});

it('prompts with singular label for one patch and skips when user declines', function () {
    $this->tempJson = tempnam(sys_get_temp_dir(), 'darn_plugin_');
    file_put_contents($this->tempJson, json_encode([
        'extra' => ['patches' => [
            'drupal/core' => [['description' => 'A patch', 'url' => 'patches/a.patch']],
        ]],
    ]));
    putenv('COMPOSER='.$this->tempJson);

    $io = $this->createMock(IOInterface::class);
    $io->method('isInteractive')->willReturn(true);
    $io->expects($this->once())
        ->method('askConfirmation')
        ->with($this->matchesRegularExpression('/1 existing patch[^s]/'), true)
        ->willReturn(false);

    $plugin = new TestPlugin();
    $plugin->activate($this->composer, $io);
    $plugin->onPostPackageInstall($this->event);

    expect($plugin->runFixCommandCalls)->toBe(0);
});

it('prompts with plural label and runs darn:fix when user confirms', function () {
    $this->tempJson = tempnam(sys_get_temp_dir(), 'darn_plugin_');
    file_put_contents($this->tempJson, json_encode([
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Patch A', 'url' => 'patches/a.patch'],
                ['description' => 'Patch B', 'url' => 'patches/b.patch'],
            ],
            'some/module' => [
                ['description' => 'Patch C', 'url' => 'patches/c.patch'],
            ],
        ]],
    ]));
    putenv('COMPOSER='.$this->tempJson);

    $io = $this->createMock(IOInterface::class);
    $io->method('isInteractive')->willReturn(true);
    $io->expects($this->once())
        ->method('askConfirmation')
        ->with($this->matchesRegularExpression('/3 existing patches/'), true)
        ->willReturn(true);

    $plugin = new TestPlugin();
    $plugin->activate($this->composer, $io);
    $plugin->onPostPackageInstall($this->event);

    expect($plugin->runFixCommandCalls)->toBe(1);
});
