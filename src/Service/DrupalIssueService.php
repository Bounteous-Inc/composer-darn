<?php

declare(strict_types=1);

namespace Bounteous\Darn\Service;

use Bounteous\Darn\Api\DrupalOrgClient;
use Composer\IO\IOInterface;

/**
 * Fetches and aggregates patch sources for a Drupal.org issue.
 */
class DrupalIssueService
{
    public function __construct(private DrupalOrgClient $client, private readonly IOInterface $io)
    {
    }

    /**
     * Returns the Drupal project machine name from issue data.
     *
     * Falls back to a separate project API call when the machine name is absent
     * from the issue node but a project ID is present.
     *
     * @param  array{field_project?: array{machine_name?: string, id?: string}}  $issueData
     */
    public function resolveProjectMachineName(array $issueData): ?string
    {
        $projectMachineName = $issueData['field_project']['machine_name'] ?? null;

        if (($projectMachineName === null || $projectMachineName === '') && isset($issueData['field_project']['id'])) {
            $projectId = $issueData['field_project']['id'];
            $this->io->write("Fetching project details for ID: <info>$projectId</info>...", true, IOInterface::VERBOSE);

            $projectData = $this->client->fetchProjectDetails($projectId);
            if ($projectData !== null) {
                $projectMachineName = $projectData['field_project_machine_name']['machine_name'] ??
                                      $projectData['machine_name'] ??
                                      null;
            }
        }

        return $projectMachineName;
    }

    /**
     * Maps a Drupal project machine name to its Composer package name.
     *
     * The core Drupal project uses the package name 'drupal/core' rather than
     * the generic 'drupal/drupal' convention.
     */
    public function getPackageName(string $projectMachineName): string
    {
        return $projectMachineName === 'drupal' ? 'drupal/core' : "drupal/$projectMachineName";
    }

    /**
     * Collects all available patches for the issue, sorted newest-first.
     *
     * Sources: open GitLab Merge Requests and .patch file attachments.
     *
     * @param  array{field_issue_files?: list<array{file: array{id?: string, cid?: string|null}}>, comments?: list<array{id: string}>}  $issueData
     * @return list<array{name: string, url: string, timestamp: int, _cid: string, display_name?: string, _is_mr?: bool, iid?: string, sha?: string|null, comment_index?: int|null, filesize?: int}>
     */
    public function collectPatches(array $issueData, string $projectMachineName, string $issueId): array
    {
        $patches = [];

        $this->io->write('Checking for Merge Requests...');
        try {
            $patches = array_merge($patches, $this->fetchMergeRequests($projectMachineName, $issueId));
        } catch (\Exception $e) {
            $this->io->writeError('Failed to fetch MRs: '.$e->getMessage(), true, IOInterface::VERBOSE);
        }

        $files = $issueData['field_issue_files'] ?? [];
        if ($files !== []) {
            $this->io->write('Scanning '.count($files).' files...');
            $patches = array_merge($patches, $this->fetchFilePatches($files, $this->buildCommentIndices($issueData)));
        }

        usort($patches, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $patches;
    }

    /**
     * Fetches open GitLab Merge Requests and formats them as patch entries.
     *
     * @return list<array{name: string, url: string, _cid: string, _is_mr: true, display_name: string, timestamp: int, iid: string, title: string, sha: string|null}>
     */
    private function fetchMergeRequests(string $projectMachineName, string $issueId): array
    {
        $mrs = $this->client->fetchMergeRequests($projectMachineName, $issueId);

        $patches = [];
        foreach ($mrs as $mr) {
            $patches[] = [
                'name' => "MR !{$mr['iid']}: {$mr['title']}",
                'url' => $mr['web_url'].'.patch',
                '_cid' => 'mr'.$mr['iid'],
                '_is_mr' => true,
                'display_name' => "MR !{$mr['iid']}",
                'timestamp' => isset($mr['updated_at']) ? strtotime($mr['updated_at']) : 0,
                'iid' => $mr['iid'],
                'title' => $mr['title'],
                'sha' => $mr['sha'] ?? null,
            ];
        }

        return $patches;
    }

    /**
     * Fetches file metadata for each attachment and returns those that are .patch files.
     *
     * @param  list<array{file: array{id?: string, cid?: string|null}}>  $files
     * @param  array<string, int>  $commentIndices  Map of comment entity ID to sequential comment number.
     * @return list<array{name: string, url: string, timestamp: int, _cid: string, comment_index: int|null, filesize: int}>
     */
    private function fetchFilePatches(array $files, array $commentIndices): array
    {
        $patches = [];
        foreach ($files as $index => $fileRef) {
            // Print a progress dot every 5 files to reassure the user that work
            // is ongoing while we make individual API calls for each attachment.
            if ($index > 0 && $index % 5 === 0) {
                $this->io->write('.', false);
            }
            if (! isset($fileRef['file']['id'])) {
                continue;
            }
            $fileId = $fileRef['file']['id'];
            $cid = $fileRef['file']['cid'] ?? null;

            try {
                $fileData = $this->client->fetchFileDetails($fileId);

                if (isset($fileData['name']) && substr($fileData['name'], -6) === '.patch') {
                    $patches[] = [
                        'name' => (string) $fileData['name'],
                        'url' => (string) ($fileData['url'] ?? ''),
                        'timestamp' => isset($fileData['timestamp']) ? (int) $fileData['timestamp'] : 0,
                        '_cid' => $cid !== null ? $cid : $fileId,
                        'comment_index' => isset($cid) && isset($commentIndices[$cid]) ? $commentIndices[$cid] : null,
                        'filesize' => isset($fileData['filesize']) ? (int) $fileData['filesize'] : 0,
                    ];
                }
            } catch (\Exception $e) {
                $this->io->writeError("Failed to fetch details for file $fileId: ".$e->getMessage(), true, IOInterface::VERBOSE);
            }
        }

        return $patches;
    }

    /**
     * Builds a map of comment entity IDs to sequential 1-based comment numbers.
     *
     * Drupal.org comment IDs are non-sequential global entity IDs, so we sort
     * them numerically and assign ordinal positions (1, 2, 3 …) that match
     * the familiar "#comment-N" references displayed on the issue page.
     *
     * @param  array{comments?: list<array{id: string}>}  $issueData
     * @return array<string, int> Map of comment entity ID to 1-based comment index.
     */
    private function buildCommentIndices(array $issueData): array
    {
        $commentIndices = [];
        if (isset($issueData['comments'])) {
            $cids = array_column($issueData['comments'], 'id');
            sort($cids, SORT_NUMERIC);
            foreach ($cids as $index => $cid) {
                $commentIndices[$cid] = $index + 1;
            }
        }

        return $commentIndices;
    }
}
