<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers a patch from a direct URL or local file path.
 *
 * For URLs (http/https), the patch is downloaded into the project's patches
 * directory. For local paths, the file is copied into the patches directory
 * unless it is already located there, in which case it is registered in-place.
 *
 * Unlike darn:github and darn:drupal.org, this command makes no assumptions
 * about the source provider. The package name cannot be auto-detected, so it
 * must be supplied via --package or entered interactively.
 */
class DirectPatchCommand extends BasePatchCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:patch')
            ->setDescription('Register a patch from a direct URL or local file path.')
            ->addArgument('source', InputArgument::REQUIRED, 'URL or local path to a .patch or .diff file.')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Composer package name to patch (e.g. drupal/core).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $source = $input->getArgument('source');

        $isUrl = str_starts_with($source, 'http://') || str_starts_with($source, 'https://');

        if ($isUrl) {
            $parsedPath = parse_url($source, PHP_URL_PATH);
            $sourcePath = ($parsedPath !== null && $parsedPath !== false) ? $parsedPath : '';
            if (! str_ends_with($sourcePath, '.patch') && ! str_ends_with($sourcePath, '.diff')) {
                $io->writeError('<error>URL must point directly to a .patch or .diff file.</error>');

                return 1;
            }
            $filename = basename($sourcePath);
        } else {
            if (! str_ends_with($source, '.patch') && ! str_ends_with($source, '.diff')) {
                $io->writeError('<error>Path must point to a .patch or .diff file.</error>');

                return 1;
            }
            if (! file_exists($source)) {
                $io->writeError("<error>File not found: $source</error>");

                return 1;
            }
            $filename = basename($source);
        }

        // Resolve package name — required but not auto-detectable from the source.
        $packageName = $input->getOption('package');
        if ($packageName === null || $packageName === '') {
            if (! $input->isInteractive()) {
                $io->writeError('<error>--package is required in non-interactive mode.</error>');

                return 1;
            }
            $packageName = trim($io->ask('Package name (e.g. drupal/core): ', '') ?? '');
            if ($packageName === '') {
                $io->writeError('<error>Package name is required.</error>');

                return 1;
            }
        }

        // Build destination path.
        $patchesDir = $this->getPatchesDirectory($input->getOption('dir'));
        $packageDir = $patchesDir.'/'.$packageName;

        if (! is_dir($packageDir)) {
            if (! mkdir($packageDir, 0777, true) && ! is_dir($packageDir)) {
                $io->writeError("<error>Failed to create directory $packageDir</error>");

                return 1;
            }
        }

        $filepath = $packageDir.'/'.$filename;

        if ($isUrl) {
            $io->write("Downloading <info>$source</info>...");
            try {
                $this->getServiceFactory()->getGuzzleClient()->request('GET', $source, ['sink' => $filepath]);
            } catch (\Exception $e) {
                $io->writeError('<error>Failed to download patch: '.$e->getMessage().'</error>');

                return 1;
            }
            $io->write("<info>Saved patch to $filepath</info>");
        } else {
            $absoluteSource = realpath($source);
            $absoluteDest = realpath($packageDir).DIRECTORY_SEPARATOR.$filename;
            if ($absoluteSource !== $absoluteDest) {
                if (! copy($source, $filepath)) {
                    $io->writeError("<error>Failed to copy file to $filepath</error>");

                    return 1;
                }
                $io->write("<info>Copied patch to $filepath</info>");
            }
        }

        $descriptionOption = $input->getOption('description');
        if ($descriptionOption !== null) {
            $description = $descriptionOption;
        } else {
            $defaultDescription = pathinfo($filename, PATHINFO_FILENAME);
            $trimmed = trim(
                $io->ask("Description [<comment>$defaultDescription</comment>]: ", $defaultDescription) ?? $defaultDescription
            );
            $description = $trimmed !== '' ? $trimmed : $defaultDescription;
        }

        $ticketOption = $input->getOption('ticket');
        $ticket = ($ticketOption !== null && $ticketOption !== false) ? (string) $ticketOption : null;

        return $this->registerPatch($filepath, $packageName, $description, null, $this->getDepthOption($input), $io, $ticket);
    }
}
