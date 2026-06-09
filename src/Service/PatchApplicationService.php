<?php

declare(strict_types=1);

namespace Bounteous\Darn\Service;

use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies registered patches by delegating to the appropriate Composer command.
 */
class PatchApplicationService
{
    /**
     * Runs the appropriate Composer command(s) to apply patches.
     *
     * @param  Application  $app  The running Composer application instance.
     * @param  InputInterface  $input  Original command input (used to check interactivity).
     * @param  OutputInterface  $output  Output stream for command output.
     * @param  IOInterface  $io  IO interface for user-facing status messages.
     */
    public function apply(
        Application $app,
        InputInterface $input,
        OutputInterface $output,
        IOInterface $io
    ): void {
        // Detect cweagans/composer-patches v2 by checking for its dedicated command.
        $useV2 = false;

        try {
            if ($app->has('patches-relock')) {
                $useV2 = true;
            }
        } catch (\Exception) {
            // Ignore exceptions when checking for command existence.
        }

        $arrayInput = new ArrayInput([]);
        $arrayInput->setInteractive($input->isInteractive());

        if ($useV2) {
            $io->write('<info>Locking and applying patches...</info>');
            if ($app->find('patches-relock')->run($arrayInput, $output) !== 0) {
                $io->writeError('<error>Failed to lock patches.</error>');

                return;
            }

            if ($app->find('patches-repatch')->run($arrayInput, $output) !== 0) {
                $io->writeError('<error>Failed to apply patches.</error>');
            }
        } else {
            $io->write('<info>Applying patches...</info>');
            if ($app->find('install')->run($arrayInput, $output) !== 0) {
                $io->writeError('<error>Failed to apply patches.</error>');
            }
        }
    }
}
