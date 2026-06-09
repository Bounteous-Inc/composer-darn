<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchEntry;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Downloads a patch from a Drupal.org issue and registers it in composer.json.
 *
 * Flow:
 *   1. Fetch the issue node from the Drupal.org REST API.
 *   2. Resolve the project machine name (from issue data or a follow-up API call).
 *   3. Collect available patches: open GitLab Merge Requests + .patch file attachments.
 *   4. Prompt the user to select a patch (sorted newest-first).
 *   5. Download the selected patch to patches/<package>/ and update composer.json.
 */
class DrupalOrgPatchCommand extends BasePatchCommand
{
    /**
     * Maps Drupal.org field_issue_status IDs to human-readable labels for display.
     *
     * @var array<int, string>
     */
    private const ISSUE_STATUS_MAP = [
        1 => 'Active',
        2 => 'Fixed',
        7 => 'Closed (won\'t fix)',
        8 => 'Needs review',
        13 => 'Needs work',
        14 => 'RTBC',
        15 => 'Patch (to be ported)',
        16 => 'Postponed',
        18 => 'Postponed (maintainer needs more info)',
    ];

    /**
     * Returns the human-readable label for a Drupal.org issue status ID,
     * or a fallback string if the ID is not in the map.
     */
    public static function getStatusLabel(int $statusId): string
    {
        return self::ISSUE_STATUS_MAP[$statusId] ?? "Unknown Status ($statusId)";
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:drupal.org')
            ->setDescription('Download and register a patch from a Drupal.org issue.')
            ->addArgument('issue_id', InputArgument::OPTIONAL, 'The numeric Drupal.org issue ID (e.g. 3456789).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueId = $input->getArgument('issue_id');
        if ($issueId === null || $issueId === '') {
            $this->getIO()->writeError('<error>The issue_id argument is required.</error>');

            return 1;
        }

        $io = $this->getIO();

        $io->write("Fetching issue details for ID: <info>$issueId</info>...");

        try {
            $issueData = $this->getServiceFactory()->getDrupalOrgClient()->fetchIssueData($issueId);
        } catch (\Exception $e) {
            $io->writeError('<error>Failed to fetch issue data: '.$e->getMessage().'</error>');

            return 1;
        }

        $this->displayIssueSummary($issueData, $issueId, $io);
        $issueTitle = self::sanitizeIssueTitle($issueData['title'] ?? 'Unknown Title');

        $issueService = $this->getServiceFactory()->getDrupalIssueService();
        $projectMachineName = $issueService->resolveProjectMachineName($issueData);

        if ($projectMachineName === null || $projectMachineName === '') {
            $io->writeError('<error>Could not determine project machine name.</error>');

            return 1;
        }

        $packageName = $issueService->getPackageName($projectMachineName);
        $io->write("Project: <info>$packageName</info>");

        $patches = $issueService->collectPatches($issueData, $projectMachineName, $issueId);

        if ($patches === []) {
            $io->writeError('<error>No .patch files or Merge Requests found.</error>');

            return 1;
        }

        $selectedPatch = $this->selectPatch($patches, $io);

        return $this->processPatch($selectedPatch, $packageName, $issueId, $issueTitle, $this->getDepthOption($input), $input, $io);
    }

    /**
     * Builds a deterministic local filename for a Drupal.org patch.
     *
     * MR patches embed the short SHA for uniqueness; file attachments embed
     * the comment index to reflect their position in the issue thread.
     *
     * @param  array<mixed>  $patch
     */
    public static function buildPatchFilename(string $issueId, array $patch): string
    {
        if (isset($patch['_is_mr']) && $patch['_is_mr'] === true) {
            $shaSuffix = isset($patch['sha']) ? '-'.substr($patch['sha'], 0, 8) : '';

            return sprintf('%s-mr-%s%s.patch', $issueId, $patch['iid'], $shaSuffix);
        }

        $commentIndex = $patch['comment_index'] ?? '0';

        return sprintf('%s-%s-%s', $issueId, $commentIndex, basename((string) $patch['name']));
    }

    /**
     * Downloads the selected patch file and registers it in composer.json.
     *
     * @param  array<mixed>  $patch
     */
    protected function processPatch(array $patch, string $packageName, string $issueId, string $issueTitle, ?int $depth, InputInterface $input, IOInterface $io): int
    {
        $url = $patch['url'];

        $basePatchDir = $this->getPatchesDirectory($input->getOption('dir'));
        $patchDir = $basePatchDir.'/'.$packageName;
        if (! is_dir($patchDir)) {
            if (! mkdir($patchDir, 0755, true) && ! is_dir($patchDir)) {
                $io->writeError("<error>Failed to create directory: $patchDir</error>");

                return 1;
            }
        }

        $filename = self::buildPatchFilename($issueId, $patch);
        $filepath = "$patchDir/$filename";

        $this->cleanupOldPatch($packageName, $issueId, $filepath, $input, $io);

        $io->write("Downloading patch to <info>$filepath</info>...");

        try {
            $this->getServiceFactory()->getDrupalOrgClient()->downloadFile($url, $filepath);
        } catch (\Exception $e) {
            $io->writeError('<error>Failed to download patch: '.$e->getMessage().'</error>');

            return 1;
        }

        $patchLabel = $patch['display_name'] ?? $patch['name'];
        $description = sprintf('Issue #%s: %s (%s)', $issueId, $issueTitle, $patchLabel);
        $issueUrl = "https://www.drupal.org/node/$issueId";

        $ticketOption = $input->getOption('ticket');
        $ticket = ($ticketOption !== null && $ticketOption !== false) ? (string) $ticketOption : null;

        return $this->registerPatch($filepath, $packageName, $description, $issueUrl, $depth, $io, $ticket);
    }

    /**
     * Displays issue title and status to the user.
     *
     * @param  array<mixed>  $issueData
     */
    private function displayIssueSummary(array $issueData, string $issueId, IOInterface $io): void
    {
        $issueTitle = $issueData['title'] ?? 'Unknown Title';
        $issueStatusId = (int) ($issueData['field_issue_status'] ?? 0);
        $issueStatus = self::getStatusLabel($issueStatusId);

        $io->write("<info>Issue #$issueId: $issueTitle\nStatus: $issueStatus</info>");
    }

    /**
     * Presents available patches and prompts the user to select one.
     *
     * @param  array<mixed>  $patches
     * @return array<mixed>
     */
    private function selectPatch(array $patches, IOInterface $io): array
    {
        $choices = [];
        foreach ($patches as $index => $patch) {
            $date = date('Y-m-d', $patch['timestamp']);
            if (isset($patch['_is_mr'])) {
                $label = "[MR !{$patch['iid']}] {$patch['title']} ({$date})";
            } else {
                $commentInfo = isset($patch['comment_index']) ? "[Comment #{$patch['comment_index']}] " : '';
                $size = $this->formatBytes($patch['filesize'] ?? 0);
                $label = "{$commentInfo}{$patch['name']} ($size) ({$date})";
            }

            // Guard against duplicate labels (e.g. two attachments with the same
            // filename uploaded on the same date) by appending a numeric suffix.
            $originalLabel = $label;
            $suffix = 1;
            while (in_array($label, $choices, true)) {
                $label = "$originalLabel ($suffix)";
                $suffix++;
            }
            $choices[$index] = $label;
        }

        $defaultChoice = $choices[0];
        $selection = $io->select('Select a patch to apply:', $choices, $defaultChoice);

        // IOInterface::select() is underspecified: interactive implementations
        // (ConsoleIO) return the label string, while non-interactive stubs or
        // mocks often return the numeric index directly. Try the label lookup
        // first; if it misses, treat the value as a direct $patches index.
        $selectedPatchKey = array_search($selection, $choices, true);

        if ($selectedPatchKey === false && isset($patches[$selection])) {
            $selectedPatchKey = $selection;
        }

        $selectedPatch = $patches[$selectedPatchKey];
        $io->write('Selected patch: <info>'.($selectedPatch['display_name'] ?? $selectedPatch['name']).'</info>');

        return $selectedPatch;
    }

    /**
     * Sanitises the issue title for use as a patch description label.
     */
    public static function sanitizeIssueTitle(string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $title));
    }

    /**
     * If an existing patch for the same issue is found, offers to delete the file and remove
     * the entry from composer.json before downloading the new version.
     */
    private function cleanupOldPatch(string $packageName, string $issueId, string $newFilepath, InputInterface $input, IOInterface $io): void
    {
        try {
            $json = $this->getServiceFactory()->getPatchManager()->readComposerJson();
        } catch (\Exception $e) {
            $io->writeError('<warning>Could not read composer.json for cleanup: '.$e->getMessage().'</warning>');

            return;
        }

        $existingPatches = $json['extra']['patches'][$packageName] ?? [];
        $oldEntry = null;

        foreach ($existingPatches as $key => $value) {
            $entry = PatchEntry::fromComposerData($key, $value);
            if (str_starts_with($entry->description, "Issue #$issueId:")) {
                $oldEntry = $entry;
                break;
            }
        }

        if ($oldEntry === null || $oldEntry->url === null || $oldEntry->url === $newFilepath || ! file_exists($oldEntry->url)) {
            return;
        }

        $doDelete = true;
        if ($input->isInteractive()) {
            $doDelete = $io->askConfirmation("Found existing patch for this issue: <comment>{$oldEntry->url}</comment>. Delete it? [Y/n] ", true);
        }

        if ($doDelete) {
            unlink($oldEntry->url);
            $io->write("Deleted old patch: {$oldEntry->url}");
            $this->getServiceFactory()->getPatchManager()->removePatch($packageName, $oldEntry->description);
            $io->write('Removed old patch entry from composer.json');
        }
    }

    /** Formats a byte count as a human-readable string (e.g. "12.5 KB"). */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        // Divide by 1024^$pow using a left-shift: (1 << (10 * $pow)) == 1024^$pow.
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[(int) $pow];
    }
}
