<?php

declare(strict_types=1);

namespace Bounteous\Darn\Service;

use Composer\Console\Application;
use Composer\InstalledVersions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manages the lifecycle of the cweagans/composer-patches dependency.
 */
class ComposerPatchesInstaller
{
    /**
     * Returns true if cweagans/composer-patches is present in the project.
     */
    public function isInstalled(): bool
    {
        return InstalledVersions::isInstalled('cweagans/composer-patches');
    }

    /**
     * Runs `composer require cweagans/composer-patches` via the Composer application.
     *
     * @param  Application  $app  The running Composer application instance.
     * @param  InputInterface  $input  Original command input (used to check interactivity).
     * @param  OutputInterface  $output  Output stream passed through to the sub-command.
     */
    public function install(Application $app, InputInterface $input, OutputInterface $output): void
    {
        $requireInput = new ArrayInput(['packages' => ['cweagans/composer-patches']]);
        $requireInput->setInteractive($input->isInteractive());
        $app->find('require')->run($requireInput, $output);
    }
}
