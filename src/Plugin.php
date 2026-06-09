<?php

declare(strict_types=1);

namespace Bounteous\Darn;

use Bounteous\Darn\Command\FixCommand;
use Bounteous\Darn\Patch\PatchManager;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class Plugin implements Capable, PluginInterface, EventSubscriberInterface
{
    private Composer $composer;

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        if (! $operation instanceof InstallOperation) {
            return;
        }

        if ($operation->getPackage()->getName() !== 'bounteous/composer-darn') {
            return;
        }

        if (! $this->io->isInteractive()) {
            return;
        }

        $patches = $this->readAllPatches();

        if ($patches === []) {
            return;
        }

        $total = array_sum(array_map('count', $patches));
        $label = $total === 1 ? 'patch' : 'patches';

        $run = $this->io->askConfirmation(
            "<info>darn</info>: Found {$total} existing {$label}. Normalize them now with <comment>darn:fix</comment>? [Y/n] ",
            true
        );

        if (! $run) {
            return;
        }

        $this->runFixCommand();
    }

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => \Bounteous\Darn\CommandProvider::class,
        ];
    }

    protected function runFixCommand(): void
    {
        $command = new FixCommand();
        $command->setIO($this->io);
        $command->setComposer($this->composer);
        $command->run(new ArrayInput([]), new NullOutput());
    }

    /**
     * @return array<string, mixed>
     */
    private function readAllPatches(): array
    {
        try {
            $data = (new PatchManager($this->io))->readComposerJson();

            return $data['extra']['patches'] ?? [];
        } catch (\Exception) {
            return [];
        }
    }
}
