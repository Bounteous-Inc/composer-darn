<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Composer\Factory;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes one or more patch entries from composer.json.
 *
 * With both arguments provided, removes non-interactively (suitable for scripts).
 * With only the package argument, shows an interactive numbered list for that package.
 * With no arguments, first selects a package interactively, then shows the list.
 *
 * --delete removes the associated file(s) from disk without prompting.
 */
class RemoveCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:remove')
            ->setDescription('Remove a patch from composer.json.')
            ->addArgument('package', InputArgument::OPTIONAL, 'Package name (e.g. drupal/core).')
            ->addArgument('description', InputArgument::OPTIONAL, 'Exact patch description to remove.')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Also delete the patch file(s) from disk.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $packageArg = $input->getArgument('package');
        $descriptionArg = $input->getArgument('description');
        $deleteFlag = $input->getOption('delete');
        $interactive = $input->isInteractive();

        if (! $interactive && ($packageArg === null || $packageArg === '' || $descriptionArg === null || $descriptionArg === '')) {
            $io->writeError('<error>Both package and description arguments are required in non-interactive mode.</error>');

            return 1;
        }

        try {
            $json = $this->getServiceFactory()->getPatchManager()->readComposerJson();
        } catch (\Exception $e) {
            $io->writeError('<error>'.$e->getMessage().'</error>');

            return 1;
        }

        /** @var array<string, array<string, string>> $allPatches */
        $allPatches = $json['extra']['patches'] ?? [];

        if ($allPatches === []) {
            $io->write('<info>No patches found in composer.json.</info>');

            return 0;
        }

        // --- Step 1: resolve package ---
        $packageName = $packageArg;

        if ($packageName === null || $packageName === '') {
            $packageNames = array_keys($allPatches);
            sort($packageNames);
            $selected = $io->select('Select package:', $packageNames, false);
            // ConsoleIO returns the label string; non-interactive stubs may return a numeric index.
            $packageName = isset($allPatches[$selected]) ? $selected : ($packageNames[$selected] ?? null);
        } elseif (! isset($allPatches[$packageName])) {
            $io->writeError("<error>No patches found for package: $packageName</error>");

            return 1;
        }

        if ($packageName === null || ! isset($allPatches[$packageName])) {
            $io->writeError('<error>Could not resolve package name from selection.</error>');

            return 1;
        }

        $packagePatches = $allPatches[$packageName];
        if ($packagePatches === []) {
            $io->write("<info>No patches found for $packageName.</info>");

            return 0;
        }

        $entries = [];
        foreach ($packagePatches as $key => $value) {
            $entries[] = PatchEntry::fromComposerData($key, $value);
        }

        // --- Step 2: resolve entries to remove ---
        if ($descriptionArg !== null) {
            $match = null;
            foreach ($entries as $entry) {
                if ($entry->description === $descriptionArg) {
                    $match = $entry;
                    break;
                }
            }
            if ($match === null) {
                $io->writeError("<error>No patch found with description: $descriptionArg</error>");

                return 1;
            }
            $toRemove = [$match];
        } else {
            $toRemove = $this->selectEntries($entries, $packageName, $io);
            if ($toRemove === null) {
                $io->write('Cancelled.');

                return 0;
            }
            if ($toRemove === []) {
                return 1; // error already written by selectEntries
            }
        }

        // --- Step 3: confirm (interactive only; both args supplied → skip in non-interactive) ---
        if ($interactive) {
            $count = count($toRemove);
            $noun = $count === 1 ? 'patch' : 'patches';
            if (! $io->askConfirmation("Remove $count $noun from <comment>$packageName</comment>? ", false)) {
                $io->write('Cancelled.');

                return 0;
            }
        }

        // --- Step 4: remove from composer.json ---
        $patchManager = $this->getServiceFactory()->getPatchManager();
        $composerDir = dirname(Factory::getComposerFile());

        foreach ($toRemove as $entry) {
            $patchManager->removePatch($packageName, $entry->description);
        }

        $count = count($toRemove);
        $noun = $count === 1 ? 'patch' : 'patches';
        $io->write("<info>Removed $count $noun from $packageName.</info>");

        // --- Step 5: optionally delete files ---
        $filesToDelete = array_values(array_filter(
            $toRemove,
            fn ($e) => $e->url !== null && file_exists($composerDir.'/'.$e->url)
        ));

        if ($filesToDelete !== []) {
            $shouldDelete = ($deleteFlag !== null && $deleteFlag !== false) || ($interactive && $io->askConfirmation('Also delete patch file(s) from disk? ', false));
            if ($shouldDelete) {
                foreach ($filesToDelete as $entry) {
                    $path = $composerDir.'/'.$entry->url;
                    unlink($path);
                    $io->write("Deleted: {$entry->url}");
                }
            }
        }

        return 0;
    }

    /**
     * Displays a numbered list of patch entries and prompts for a selection.
     *
     * Returns the selected entries, null if the user cancelled (blank input), or an
     * empty array if the input was invalid (error already written to IO).
     *
     * @param  list<PatchEntry>  $entries
     * @return list<PatchEntry>|null
     */
    private function selectEntries(array $entries, string $packageName, IOInterface $io): ?array
    {
        $composerDir = dirname(Factory::getComposerFile());

        $io->write("\n<comment>$packageName</comment>");
        foreach ($entries as $i => $entry) {
            $num = $i + 1;
            $url = $entry->url ?? '(no url)';
            $exists = $entry->url !== null && file_exists($composerDir.'/'.$entry->url);
            $status = $exists ? '<info>✓</info>' : '<error>✗</error>';
            $io->write("  $num. [$status] {$entry->description} ($url)");
        }

        $response = trim($io->ask("\nEnter numbers to remove (e.g. 1,2), * for all, or blank to cancel: ", '') ?? '');

        if ($response === '') {
            return null;
        }

        if ($response === '*') {
            return $entries;
        }

        $selected = [];
        foreach (explode(',', $response) as $raw) {
            $part = trim($raw);
            $idx = ((int) $part) - 1;
            if (! is_numeric($part) || $idx < 0 || ! isset($entries[$idx])) {
                $io->writeError("<error>Invalid selection: $part</error>");

                return [];
            }
            $selected[] = $entries[$idx];
        }

        return $selected;
    }
}
