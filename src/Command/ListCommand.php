<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Composer\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all patches registered in composer.json, grouped by package.
 *
 * Each entry shows whether its patch file exists on disk ([✓] present,
 * [✗] missing). An optional package argument limits output to a single
 * package. Always exits 0 — use darn:verify for a CI-friendly check.
 */
class ListCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:list')
            ->setDescription('List patches registered in composer.json.')
            ->addArgument('package', InputArgument::OPTIONAL, 'Limit output to a specific package.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $targetPackage = $input->getArgument('package');

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

        $composerDir = dirname(Factory::getComposerFile());
        $totalCount = 0;
        $missingCount = 0;
        $packageCount = 0;

        ksort($patches);

        foreach ($patches as $packageName => $packagePatches) {
            if ($targetPackage !== null && $packageName !== $targetPackage) {
                continue;
            }

            if (! is_array($packagePatches) || $packagePatches === []) {
                continue;
            }

            $io->write("<comment>$packageName</comment>");
            $packageCount++;

            foreach ($packagePatches as $key => $patchInfo) {
                $entry = PatchEntry::fromComposerData($key, $patchInfo);
                $totalCount++;

                if ($entry->url === null) {
                    $io->write("  [<error>!</error>] {$entry->description} <error>(no url)</error>");
                    $missingCount++;
                    continue;
                }

                $ticketTag = $entry->ticket !== null ? " <comment>[{$entry->ticket}]</comment>" : '';
                $fullPath = $composerDir.'/'.$entry->url;
                if (file_exists($fullPath)) {
                    $io->write("  [<info>✓</info>] {$entry->description}{$ticketTag} ({$entry->url})");
                } else {
                    $io->write("  [<error>✗</error>] {$entry->description}{$ticketTag} ({$entry->url})");
                    $missingCount++;
                }
            }
        }

        if ($packageCount === 0) {
            $io->write('<info>No patches found for that package.</info>');

            return 0;
        }

        $io->write('');
        $summary = "$totalCount patch(es) registered for $packageCount package(s).";
        if ($missingCount > 0) {
            $summary .= " <error>$missingCount missing.</error>";
        }
        $io->write($summary);

        return 0;
    }
}
