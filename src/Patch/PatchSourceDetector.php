<?php

declare(strict_types=1);

namespace Bounteous\Darn\Patch;

/**
 * Identifies the source type of a patch URL and extracts provider-specific identifiers.
 */
final class PatchSourceDetector
{
    public const TYPE_DRUPAL = 'drupal';

    public const TYPE_GITHUB = 'github';

    public const TYPE_LOCAL = 'local';

    public const TYPE_UNKNOWN = 'unknown';

    /**
     * Detects the source type of a patch URL.
     *
     * Returns TYPE_LOCAL for null, empty strings, and non-HTTP paths.
     */
    public static function detect(?string $url): string
    {
        if ($url === null || $url === '') {
            return self::TYPE_LOCAL;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return self::TYPE_LOCAL;
        }

        if (self::extractDrupalIssueId($url) !== null) {
            return self::TYPE_DRUPAL;
        }

        if (self::extractGitHubPrInfo($url) !== null) {
            return self::TYPE_GITHUB;
        }

        return self::TYPE_UNKNOWN;
    }

    /**
     * Extracts a Drupal.org issue ID from a patch file URL.
     *
     * Matches URLs of the form:
     *   https://www.drupal.org/files/issues/YYYY-MM-DD/{issueId}-*.patch
     */
    public static function extractDrupalIssueId(string $url): ?int
    {
        if (preg_match('#drupal\.org/files/issues/[^/]+/(\d+)[^/]*\.patch#', $url, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extracts GitHub PR details from a PR page URL or raw patch/diff URL.
     *
     * Matches:
     *   https://github.com/{owner}/{repo}/pull/{n}[.patch|.diff]
     *   https://patch-diff.githubusercontent.com/raw/{owner}/{repo}/pull/{n}.patch
     *
     * @return array{owner: string, repo: string, number: int, prUrl: string}|null
     */
    public static function extractGitHubPrInfo(string $url): ?array
    {
        if (preg_match('#github\.com/([^/]+)/([^/]+)/pull/(\d+)#', $url, $m) === 1) {
            return [
                'owner' => $m[1],
                'repo' => $m[2],
                'number' => (int) $m[3],
                'prUrl' => "https://github.com/{$m[1]}/{$m[2]}/pull/{$m[3]}",
            ];
        }

        if (preg_match('#patch-diff\.githubusercontent\.com/raw/([^/]+)/([^/]+)/pull/(\d+)#', $url, $m) === 1) {
            return [
                'owner' => $m[1],
                'repo' => $m[2],
                'number' => (int) $m[3],
                'prUrl' => "https://github.com/{$m[1]}/{$m[2]}/pull/{$m[3]}",
            ];
        }

        return null;
    }
}
