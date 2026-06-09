<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes stale patch files and/or orphaned composer.json entries.
 *
 * Two categories of staleness are detected:
 *   - Orphaned files: .patch files on disk that have no matching entry in
 *     composer.json (e.g. left behind after a manual edit).
 *   - Missing entries: entries in composer.json whose file path no longer
 *     exists on disk. These are only removed when --clean-config is passed.
 *
 * An optional package argument limits the scan to a single package directory.
 */
class PruneCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:prune')
            ->setDescription('Prune orphaned patch files and missing config entries.')
            ->addArgument('package', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Limit pruning to a specific package.')
            ->addOption('clean-config', null, InputOption::VALUE_NONE, 'Remove entries from composer.json if the file is missing.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->input = $input;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $cleanConfig = $input->getOption('clean-config');
        $targetPackage = $input->getArgument('package');

        try {
            $json = $this->getServiceFactory()->getPatchManager()->readComposerJson();
        } catch (\Exception $e) {
            $io->writeError('<error>'.$e->getMessage().'</error>');

            return 1;
        }

        $definedPatches = [];
        if (isset($json['extra']['patches'])) {
            foreach ($json['extra']['patches'] as $package => $patches) {
                if ($targetPackage !== null && $package !== $targetPackage) {
                    continue;
                }
                if (is_array($patches)) {
                    foreach ($patches as $key => $patchInfo) {
                        $entry = PatchEntry::fromComposerData($key, $patchInfo);
                        if ($entry->url !== null) {
                            $definedPatches[$entry->url] = ['package' => $package, 'description' => $entry->description];
                        }
                    }
                }
            }
        }

        $diskPatches = $this->getPatchesFromDisk($targetPackage);
        $orphanedFiles = [];

        foreach ($diskPatches as $file) {
            if (! isset($definedPatches[$file])) {
                $orphanedFiles[] = $file;
            }
        }

        $missingEntries = [];
        foreach ($definedPatches as $path => $info) {
            if (! file_exists($path)) {
                $missingEntries[] = $path;
            }
        }

        if ($orphanedFiles === [] && $missingEntries === []) {
            $io->write('<info>No orphaned files or missing patches found.</info>');

            return 0;
        }

        if ($orphanedFiles !== []) {
            $io->write("\n<comment>Orphaned Files (Files on disk but not in composer.json)</comment>");
            foreach ($orphanedFiles as $file) {
                $io->write(' - '.$file);
            }

            if ($io->askConfirmation('Delete these files? ', false)) {
                foreach ($orphanedFiles as $file) {
                    unlink($file);
                    $io->write("Deleted: $file");
                }
            }
        }

        if ($missingEntries !== []) {
            $io->write("\n<comment>Missing Files (Entries in composer.json but file missing)</comment>");
            foreach ($missingEntries as $path) {
                $io->write(' - '.$path);
            }

            if ($cleanConfig !== null && $cleanConfig !== false) {
                if ($io->askConfirmation('Remove these entries from composer.json? ', false)) {
                    $patchManager = $this->getServiceFactory()->getPatchManager();
                    foreach ($missingEntries as $path) {
                        $info = $definedPatches[$path];
                        $patchManager->removePatch($info['package'], $info['description']);
                    }
                    $io->write('<info>Updated composer.json</info>');
                }
            } else {
                $io->write('<comment>Use --clean-config to remove missing entries.</comment>');
            }
        }

        return 0;
    }
}
