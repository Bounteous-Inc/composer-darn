<?php

declare(strict_types=1);

namespace Bounteous\Darn\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for the GitHub and raw.githubusercontent.com APIs.
 *
 * Responsible solely for making HTTP requests and decoding responses.
 * All orchestration, user interaction, and error display live in the command layer.
 *
 * URL parsing (extracting owner/repo/PR-number from a GitHub URL) is the
 * caller's responsibility. Methods here accept pre-parsed identifiers so the
 * URL is never parsed twice.
 */
class GitHubClient
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Attempts to detect the Composer package name for a GitHub repository.
     *
     * Queries the GitHub REST API to resolve the repository's default branch, then
     * fetches raw composer.json from that branch (falling back to main/master).
     * The first branch whose composer.json contains a 'name' key wins.
     * Returns null when the package name cannot be found.
     */
    public function detectPackageName(string $owner, string $repo): ?string
    {
        $apiUrl = "https://api.github.com/repos/$owner/$repo";
        $repoData = null;
        try {
            $repoJson = (string) $this->client->get($apiUrl)->getBody();
            $repoData = json_decode($repoJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            // ignore: use the fallback branches
        }

        $branches = ['main', 'master'];
        if (isset($repoData['default_branch'])) {
            array_unshift($branches, $repoData['default_branch']);
            $branches = array_unique($branches);
        }

        foreach ($branches as $branch) {
            $rawUrl = "https://raw.githubusercontent.com/$owner/$repo/$branch/composer.json";
            try {
                $composerJson = (string) $this->client->get($rawUrl)->getBody();
                $data = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
                if (isset($data['name'])) {
                    return $data['name'];
                }
            } catch (\Exception) {
                // skip this branch
            }
        }

        return null;
    }

    /**
     * Fetches key metadata for a GitHub Pull Request.
     *
     * Returns an array with 'title' and 'number' keys on success, or null if
     * the API call fails (e.g. rate-limited, unauthenticated, or network error).
     *
     * @return array{title: string, number: int}|null
     */
    public function fetchPullRequest(string $owner, string $repo, int $prNumber): ?array
    {
        try {
            $json = (string) $this->client->get(
                "https://api.github.com/repos/$owner/$repo/pulls/$prNumber"
            )->getBody();
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return [
                'title' => $data['title'] ?? '',
                'number' => $data['number'] ?? $prNumber,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Downloads a remote file directly to a local path using Guzzle's sink option.
     *
     * @throws GuzzleException
     */
    public function downloadPatch(string $url, string $sink): void
    {
        $this->client->get($url, ['sink' => $sink]);
    }
}
