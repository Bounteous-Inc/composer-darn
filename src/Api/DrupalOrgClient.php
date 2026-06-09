<?php

declare(strict_types=1);

namespace Bounteous\Darn\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for the Drupal.org and GitLab APIs.
 *
 * Responsible solely for making HTTP requests and decoding responses.
 * All orchestration, user interaction, and error display live in the command layer.
 */
class DrupalOrgClient
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Fetches issue node data from the Drupal.org API.
     *
     * @return array<mixed>
     *
     * @throws GuzzleException
     */
    public function fetchIssueData(string $issueId): array
    {
        $response = $this->client->get("https://www.drupal.org/api-d7/node/$issueId.json");

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Fetches a project node to resolve the machine name when it is absent from the issue data.
     * Returns null when the request fails, allowing the caller to degrade gracefully.
     *
     * @return array<mixed>|null
     */
    public function fetchProjectDetails(string $projectId): ?array
    {
        try {
            $response = $this->client->get("https://www.drupal.org/api-d7/node/$projectId.json");

            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Fetches open Merge Requests from GitLab that match the given issue.
     *
     * @return array<mixed>
     *
     * @throws GuzzleException
     */
    public function fetchMergeRequests(string $projectMachineName, string $issueId): array
    {
        $gitlabProjectPath = 'project%2F'.$projectMachineName;
        $response = $this->client->get(
            "https://git.drupalcode.org/api/v4/projects/$gitlabProjectPath/merge_requests?scope=all&state=opened&search=$issueId"
        );

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Fetches file metadata from the Drupal.org Files API.
     *
     * @return array<mixed>
     *
     * @throws GuzzleException
     */
    public function fetchFileDetails(string $fileId): array
    {
        $response = $this->client->get("https://www.drupal.org/api-d7/file/$fileId.json");

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Downloads a remote file directly to a local path using Guzzle's sink option.
     *
     * @throws GuzzleException
     */
    public function downloadFile(string $url, string $sink): void
    {
        $this->client->get($url, ['sink' => $sink]);
    }
}
