<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Bounteous\Darn\Patch\PatchSourceDetector;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Normalizes existing patch entries in composer.json by re-fetching metadata
 * from their source (Drupal.org, GitHub). Skips local-only patches.
 *
 * With --dry-run, API calls are made but nothing is written or downloaded.
 */
class FixCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:fix')
            ->setDescription('Normalize existing patch entries in composer.json by re-fetching metadata from their source.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without making any modifications.');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->applyGitHubClientOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $isDryRun = (bool) $input->getOption('dry-run');

        if ($isDryRun) {
            $io->write('<comment>Dry run — no changes will be made.</comment>');
        }

        try {
            $json = $this->getServiceFactory()->getPatchManager()->readComposerJson();
        } catch (\Exception $e) {
            $io->writeError('<error>'.$e->getMessage().'</error>');

            return 1;
        }

        $allPatches = $json['extra']['patches'] ?? [];

        if ($allPatches === []) {
            $io->write('No patches found in composer.json.');

            return 0;
        }

        $totalCount = array_sum(array_map('count', $allPatches));
        $packageCount = count($allPatches);
        $io->write(sprintf(
            'Scanning %d %s across %d %s...',
            $totalCount,
            $totalCount === 1 ? 'patch' : 'patches',
            $packageCount,
            $packageCount === 1 ? 'package' : 'packages'
        ));

        $patchesDir = $this->getPatchesDirectory($input->getOption('dir'));
        $counts = ['fixed' => 0, 'skipped' => 0, 'warning' => 0];

        foreach ($allPatches as $packageName => $packagePatches) {
            $io->write("\n  <info>$packageName</info>");

            foreach ($packagePatches as $key => $value) {
                $entry = PatchEntry::fromComposerData($key, $value);
                $result = $this->processEntry($entry, $packageName, $isDryRun, $patchesDir, $io);

                if (array_key_exists($result, $counts)) {
                    $counts[$result]++;
                }
            }
        }

        $io->write('');
        $parts = [];
        if ($counts['fixed'] > 0) {
            $parts[] = $isDryRun ? "{$counts['fixed']} would be updated" : "{$counts['fixed']} updated";
        }
        if ($counts['skipped'] > 0) {
            $parts[] = "{$counts['skipped']} skipped";
        }
        if ($counts['warning'] > 0) {
            $n = $counts['warning'];
            $parts[] = "$n ".($n === 1 ? 'warning' : 'warnings');
        }

        $io->write(implode(', ', $parts).'.');

        return 0;
    }

    private function processEntry(PatchEntry $entry, string $packageName, bool $isDryRun, string $patchesDir, IOInterface $io): string
    {
        if ($entry->url === null) {
            $io->write("    [–] \"{$entry->description}\" — no URL, skipped");

            return 'skipped';
        }

        return match (PatchSourceDetector::detect($entry->url)) {
            PatchSourceDetector::TYPE_LOCAL => $this->skipEntry($entry, 'local file', $io),
            PatchSourceDetector::TYPE_DRUPAL => $this->handleDrupalEntry($entry, $packageName, $isDryRun, $patchesDir, $io),
            PatchSourceDetector::TYPE_GITHUB => $this->handleGitHubEntry($entry, $packageName, $isDryRun, $patchesDir, $io),
            default => $this->warnEntry($entry, 'unrecognized URL', $io),
        };
    }

    private function skipEntry(PatchEntry $entry, string $reason, IOInterface $io): string
    {
        $io->write("    [–] \"{$entry->description}\" — {$reason}, skipped");

        return 'skipped';
    }

    private function warnEntry(PatchEntry $entry, string $reason, IOInterface $io): string
    {
        $io->writeError("    [!] \"{$entry->description}\" — {$reason}, skipped");

        return 'warning';
    }

    private function handleDrupalEntry(PatchEntry $entry, string $packageName, bool $isDryRun, string $patchesDir, IOInterface $io): string
    {
        $url = (string) $entry->url;
        $rawIssueId = PatchSourceDetector::extractDrupalIssueId($url);

        if ($rawIssueId === null) {
            throw new \LogicException('TYPE_DRUPAL requires extractDrupalIssueId() to succeed');
        }

        $issueId = (string) $rawIssueId;

        try {
            $issueData = $this->getServiceFactory()->getDrupalOrgClient()->fetchIssueData($issueId);
        } catch (\Exception $e) {
            return $this->warnEntry($entry, "could not fetch issue #{$issueId}: {$e->getMessage()}", $io);
        }

        $issueService = $this->getServiceFactory()->getDrupalIssueService();
        $projectMachineName = $issueService->resolveProjectMachineName($issueData);
        $newPackageName = ($projectMachineName !== null && $projectMachineName !== '') ? $issueService->getPackageName($projectMachineName) : $packageName;

        $patches = ($projectMachineName !== null && $projectMachineName !== '')
            ? $issueService->collectPatches($issueData, $projectMachineName, $issueId)
            : [];

        $matchedPatch = $this->findMatchingDrupalPatch($patches, $url);
        $issueTitle = DrupalOrgPatchCommand::sanitizeIssueTitle($issueData['title'] ?? 'Unknown Title');

        $patchLabel = ($matchedPatch !== null) ? ($matchedPatch['display_name'] ?? $matchedPatch['name']) : null;
        $newDescription = ($patchLabel !== null)
            ? "Issue #{$issueId}: {$issueTitle} ({$patchLabel})"
            : "Issue #{$issueId}: {$issueTitle}";

        $newIssueUrl = "https://www.drupal.org/node/{$issueId}";
        $newFilepath = $this->buildDrupalLocalPath($patchesDir, $newPackageName, $issueId, $matchedPatch);

        if ($isDryRun) {
            $this->displayDiff($entry, $packageName, $newDescription, $newIssueUrl, $newFilepath, $newPackageName, $io);

            return 'fixed';
        }

        $sf = $this->getServiceFactory();
        $downloadUrl = $url;
        $download = static fn (string $dest) => $sf->getDrupalOrgClient()->downloadFile($downloadUrl, $dest);

        return $this->applyFix($entry, $packageName, $download, $newDescription, $newIssueUrl, $newFilepath, $newPackageName, $io);
    }

    private function handleGitHubEntry(PatchEntry $entry, string $packageName, bool $isDryRun, string $patchesDir, IOInterface $io): string
    {
        $url = (string) $entry->url;
        $prInfo = PatchSourceDetector::extractGitHubPrInfo($url);

        if ($prInfo === null) {
            throw new \LogicException('TYPE_GITHUB requires extractGitHubPrInfo() to succeed');
        }

        ['owner' => $owner, 'repo' => $repo, 'number' => $number, 'prUrl' => $prUrl] = $prInfo;

        $pr = $this->getServiceFactory()->getGitHubClient()->fetchPullRequest($owner, $repo, $number);

        $newDescription = ($pr !== null && $pr['title'] !== '')
            ? "PR #{$number}: {$pr['title']} ({$owner}/{$repo})"
            : "Patch from {$prUrl}";

        $newIssueUrl = $prUrl;
        $newFilepath = "{$patchesDir}/{$packageName}/{$owner}-{$repo}-pr-{$number}.patch";

        if ($isDryRun) {
            $this->displayDiff($entry, $packageName, $newDescription, $newIssueUrl, $newFilepath, $packageName, $io);

            return 'fixed';
        }

        $sf = $this->getServiceFactory();
        $diffUrl = $prUrl.'.diff';
        $download = static fn (string $dest) => $sf->getGitHubClient()->downloadPatch($diffUrl, $dest);

        return $this->applyFix($entry, $packageName, $download, $newDescription, $newIssueUrl, $newFilepath, $packageName, $io);
    }

    /**
     * @param  array<mixed>  $patches
     * @return array<mixed>|null
     */
    private function findMatchingDrupalPatch(array $patches, string $url): ?array
    {
        $urlPath = parse_url($url, PHP_URL_PATH);

        foreach ($patches as $patch) {
            if (! isset($patch['url'])) {
                continue;
            }

            if ($patch['url'] === $url) {
                return $patch;
            }

            // handles http↔https and CDN domain mismatches
            if ($urlPath !== false && parse_url($patch['url'], PHP_URL_PATH) === $urlPath) {
                return $patch;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>|null  $matchedPatch
     */
    private function buildDrupalLocalPath(string $patchesDir, string $packageName, string $issueId, ?array $matchedPatch): string
    {
        $filename = $matchedPatch !== null
            ? DrupalOrgPatchCommand::buildPatchFilename($issueId, $matchedPatch)
            : "{$issueId}.patch";

        return "{$patchesDir}/{$packageName}/{$filename}";
    }

    private function displayDiff(
        PatchEntry $entry,
        string $currentPackage,
        string $newDescription,
        string $newIssueUrl,
        string $newFilepath,
        string $newPackage,
        IOInterface $io
    ): void {
        $io->write("    [~] \"{$entry->description}\"");

        if ($entry->description !== $newDescription) {
            $io->write("        description: \"{$entry->description}\" → \"{$newDescription}\"");
        }

        if ($entry->url !== $newFilepath) {
            $io->write("        url: \"{$entry->url}\" → \"{$newFilepath}\"");
        }

        if ($entry->issueTrackerUrl !== $newIssueUrl) {
            $old = $entry->issueTrackerUrl ?? '(none)';
            $io->write("        issue-tracker-url: \"{$old}\" → \"{$newIssueUrl}\"");
        }

        if ($currentPackage !== $newPackage) {
            $io->write("        package: \"{$currentPackage}\" → \"{$newPackage}\"");
        }
    }

    private function applyFix(
        PatchEntry $entry,
        string $packageName,
        \Closure $download,
        string $newDescription,
        string $newIssueUrl,
        string $newFilepath,
        string $newPackageName,
        IOInterface $io
    ): string {
        $patchDir = dirname($newFilepath);

        if (! is_dir($patchDir) && ! mkdir($patchDir, 0755, true) && ! is_dir($patchDir)) {
            return $this->warnEntry($entry, "failed to create directory {$patchDir}", $io);
        }

        try {
            $download($newFilepath);
        } catch (\Exception $e) {
            return $this->warnEntry($entry, "download failed: {$e->getMessage()}", $io);
        }

        $validator = $this->getServiceFactory()->getPatchValidator();

        if (! $validator->validate($newFilepath, $newPackageName, $this->requireComposer(), $entry->depth, $io)) {
            if (is_file($newFilepath)) {
                unlink($newFilepath);
            }

            return $this->warnEntry($entry, 'patch validation failed', $io);
        }

        if (! $this->getServiceFactory()->getPatchManager()->replacePatch($packageName, $entry->description, $newFilepath, $newPackageName, $newDescription, $newIssueUrl, $entry->depth, $entry->ticket)) {
            if (is_file($newFilepath)) {
                unlink($newFilepath);
            }

            return $this->warnEntry($entry, 'could not update composer.json', $io);
        }

        $io->write("    [~] \"{$entry->description}\"");

        if ($entry->description !== $newDescription) {
            $io->write("        → \"{$newDescription}\"");
        }

        return 'fixed';
    }
}
