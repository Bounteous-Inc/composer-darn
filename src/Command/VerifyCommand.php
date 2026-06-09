<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifies that every patch file referenced in composer.json exists on disk.
 *
 * Exits with code 0 when all patches are present, or 1 if any are missing.
 * With --prune, delegates entirely to PruneCommand which handles both
 * orphaned files and missing config entries interactively.
 */
class VerifyCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:verify')
            ->setDescription('Verify that patches listed in composer.json actually exist.')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Run darn:prune to clean up orphaned files and missing entries.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        if ($input->getOption('prune') !== false) {
            $command = $this->getApplication()->find('darn:prune');
            $dirOption = $input->getOption('dir');
            $arguments = array_filter(
                ['command' => 'darn:prune', '--dir' => $dirOption],
                fn ($v) => $v !== null
            );
            $pruneInput = new ArrayInput($arguments);
            $pruneInput->setInteractive($input->isInteractive());

            return $command->run($pruneInput, $output);
        }

        try {
            $json = $this->getServiceFactory()->getPatchManager()->readComposerJson();
        } catch (\Exception $e) {
            $io->writeError('<error>'.$e->getMessage().'</error>');

            return 1;
        }

        $patches = $json['extra']['patches'] ?? [];

        if ($patches === []) {
            $io->write('<info>No patches found in composer.json.</info>');

            return 0;
        }

        $io->write('Verifying patches...');

        $hasMissing = false;
        $missingCount = 0;
        $totalCount = 0;
        $composerDir = dirname(\Composer\Factory::getComposerFile());

        foreach ($patches as $packageName => $packagePatches) {
            if (! is_array($packagePatches)) {
                continue;
            }

            foreach ($packagePatches as $key => $patchInfo) {
                $totalCount++;

                $entry = PatchEntry::fromComposerData($key, $patchInfo);
                $description = $entry->description !== '' ? $entry->description : 'Unknown';
                $patchPath = ($entry->url !== null && $entry->url !== '') ? $entry->url : null;

                if ($patchPath === null) {
                    $io->writeError("<error>Malformed patch entry for $packageName</error>");
                    $hasMissing = true;
                    $missingCount++;

                    continue;
                }

                $fullPath = $composerDir.'/'.$patchPath;

                if (! file_exists($fullPath)) {
                    $io->writeError("<error>Missing patch for $packageName: $description ($patchPath)</error>");
                    $hasMissing = true;
                    $missingCount++;
                }
            }
        }

        // Warn about any patch file registered more than once.
        $urlMap = [];
        foreach ($patches as $pkgName => $pkgPatches) {
            if (! is_array($pkgPatches)) {
                continue;
            }
            foreach ($pkgPatches as $key => $patchInfo) {
                $entry = PatchEntry::fromComposerData($key, $patchInfo);
                if ($entry->url !== null) {
                    $urlMap[$entry->url][] = "$pkgName: {$entry->description}";
                }
            }
        }
        foreach ($urlMap as $url => $registrations) {
            if (count($registrations) > 1) {
                $io->writeError("<warning>Duplicate patch URL detected: $url</warning>");
                foreach ($registrations as $reg) {
                    $io->writeError("<warning>  Registered as: $reg</warning>");
                }
            }
        }

        // Warn if the patches directory appears to be gitignored.
        $patchesRoot = $input->getOption('dir') ?? 'patches';
        $this->warnIfPatchesDirGitignored($patchesRoot, $io, $composerDir);

        if ($hasMissing) {
            $io->writeError("<error>Verification failed. $missingCount/$totalCount patches are missing.</error>");

            return 1;
        }

        $io->write("<info>Verification successful. All $totalCount patches exist.</info>");

        return 0;
    }

    /**
     * Emits a warning if the patches directory is explicitly listed in .gitignore.
     *
     * Checks the project-root .gitignore for patterns that match the patches
     * directory name (e.g. "patches", "patches/", "/patches", "/patches/").
     * Only fires when the project is inside a git repository.
     */
    private function warnIfPatchesDirGitignored(string $patchesRoot, IOInterface $io, string $composerDir): void
    {
        if (! is_dir($composerDir.'/.git')) {
            return;
        }

        $gitignorePath = $composerDir.'/.gitignore';
        if (! file_exists($gitignorePath)) {
            return;
        }

        $normalizedRoot = trim($patchesRoot, '/');
        $rawLines = file($gitignorePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = $rawLines !== false ? $rawLines : [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (trim($line, '/') === $normalizedRoot) {
                $io->writeError(
                    "<warning>The patches directory ($patchesRoot) appears to be gitignored. ".
                    'Patches should be committed to version control.</warning>'
                );

                return;
            }
        }
    }
}
