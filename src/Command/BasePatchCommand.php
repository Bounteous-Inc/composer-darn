<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Abstract base for commands that download and register a new patch.
 *
 * Adds the --apply and --depth options, manages the optional
 * cweagans/composer-patches installation prompt, and provides
 * registerPatch() which orchestrates validation → composer.json update →
 * optional normalize → optional patch application.
 */
abstract class BasePatchCommand extends DarnCommand
{
    /** Whether to trigger a composer install / patches-repatch after registering the patch. */
    protected bool $applyPatches = false;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'apply',
            'a',
            InputOption::VALUE_NONE,
            'Automatically apply the new patch after updating composer.json.'
        );
        $this->addOption(
            'depth',
            'p',
            InputOption::VALUE_REQUIRED,
            'The patch depth to use (e.g. 0 or 1).',
            null
        );
        $this->addOption(
            'ticket',
            null,
            InputOption::VALUE_REQUIRED,
            'Internal ticket or issue reference to associate with this patch (e.g. JIRA-123).',
            null
        );
        $this->addOption(
            'description',
            null,
            InputOption::VALUE_REQUIRED,
            'Patch description written to composer.json (skips interactive prompt).',
            null
        );
    }

    /**
     * Reads --apply and validates the cweagans/composer-patches requirement early.
     *
     * In non-interactive mode a missing composer-patches package is a hard error
     * because there is no opportunity to prompt the user. In interactive mode,
     * interact() handles the prompt and optional installation instead.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->applyPatches = (bool) $input->getOption('apply');

        if ($this->applyPatches && ! $this->isPatchPackageInstalled()) {
            if (! $input->isInteractive()) {
                throw new \RuntimeException("cweagans/composer-patches is not detected. Run 'composer require cweagans/composer-patches' to fix this.");
            }
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if (! $this->isPatchPackageInstalled()) {
            $io = $this->getIO();
            $io->write('<comment>cweagans/composer-patches is not detected.</comment>');
            if ($io->askConfirmation('Would you like to install cweagans/composer-patches now? [Y/n] ', true)) {
                $this->installPatchPackage($io);
            } else {
                $io->write('<warning>Patches will not be applied automatically without cweagans/composer-patches.</warning>');
            }
        }
    }

    /**
     * Checks if the cweagans/composer-patches package is installed.
     */
    protected function isPatchPackageInstalled(): bool
    {
        return $this->getServiceFactory()->getComposerPatchesInstaller()->isInstalled();
    }

    /**
     * Installs the cweagans/composer-patches package.
     */
    protected function installPatchPackage(IOInterface $io): void
    {
        $io->write('Installing cweagans/composer-patches...');
        $this->getServiceFactory()->getComposerPatchesInstaller()->install($this->getApplication(), $this->input, $this->output);
    }

    /**
     * Returns the --depth option value cast to int, or null if the option was not given.
     */
    protected function getDepthOption(InputInterface $input): ?int
    {
        $depth = $input->getOption('depth');

        return $depth !== null ? (int) $depth : null;
    }

    /**
     * Validates, registers, and optionally applies a downloaded patch file.
     *
     * Steps:
     *   1. Validate the patch (format + dry-run applicability check).
     *      The file is deleted and the command returns 1 on failure.
     *   2. Write the patch entry to composer.json via PatchManager.
     *   3. Run `composer normalize` if available (best-effort, non-fatal).
     *   4. Run `composer install` / `patches-repatch` if --apply was given.
     *
     * @param  string  $filepath  Absolute or relative path to the saved .patch file.
     * @param  string  $packageName  Composer package name the patch targets.
     * @param  string  $description  Human-readable label stored in composer.json.
     * @param  string|null  $issueUrl  Optional upstream issue URL stored in extra.
     * @param  int|null  $depth  Strip-prefix depth passed to `git apply -p<n>`.
     * @param  IOInterface  $io  Used for user-facing status messages.
     * @return int 0 on success, 1 on any failure.
     */
    protected function registerPatch(string $filepath, string $packageName, string $description, ?string $issueUrl, ?int $depth, IOInterface $io, ?string $ticket = null): int
    {
        // Warn if this file path is already registered under a different description or package.
        try {
            $existingJson = $this->getServiceFactory()->getPatchManager()->readComposerJson();
            foreach ($existingJson['extra']['patches'] ?? [] as $existingPackage => $packagePatches) {
                if (! is_array($packagePatches)) {
                    continue;
                }
                foreach ($packagePatches as $key => $value) {
                    $existing = PatchEntry::fromComposerData($key, $value);
                    if ($existing->url === $filepath
                        && ($existingPackage !== $packageName || $existing->description !== $description)) {
                        $io->writeError(
                            "<warning>$filepath is already registered as \"{$existing->description}\" for $existingPackage.</warning>"
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // Skip check if composer.json cannot be read — addPatch() will surface the error.
        }

        $validator = $this->getServiceFactory()->getPatchValidator();
        if (! $validator->validate($filepath, $packageName, $this->requireComposer(), $depth, $io)) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return 1;
        }

        if (! $this->getServiceFactory()->getPatchManager()->addPatch($filepath, $packageName, $description, $issueUrl, $depth, $ticket)) {
            return 1;
        }

        $io->write("<info>Updated composer.json with patch for $packageName</info>");

        $this->runNormalizeIfAvailable($io);

        if ($this->applyPatches) {
            $this->triggerComposerInstall($io);
        }

        return 0;
    }

    /**
     * Runs `composer normalize` when the plugin is available in the application.
     */
    protected function runNormalizeIfAvailable(IOInterface $io): void
    {
        try {
            if (! $this->getApplication()->has('normalize')) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        try {
            $executor = new ProcessExecutor($io);
            $io->write('<info>Normalizing composer.json...</info>', true, IOInterface::VERBOSE);
            if ($executor->execute('composer normalize') !== 0) {
                $io->writeError('<warning>Could not normalize composer.json.</warning>');
            }
        } catch (\Exception $e) {
            $io->writeError('<warning>Could not run composer normalize: '.$e->getMessage().'</warning>', true, IOInterface::VERBOSE);
        }
    }

    /**
     * Applies patches by delegating to PatchApplicationService.
     */
    protected function triggerComposerInstall(IOInterface $io): void
    {
        $this->getServiceFactory()->getPatchApplicationService()->apply(
            $this->getApplication(),
            $this->input,
            $this->output,
            $io
        );
    }
}
