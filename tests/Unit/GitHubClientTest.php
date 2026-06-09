<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Api\GitHubClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function makeGitHubClient(array $responses, array &$history = []): GitHubClient
{
    return new GitHubClient(makeGuzzleClient($responses, $history));
}

// ── detectPackageName ─────────────────────────────────────────────────────────

it('detectPackageName uses the default_branch from the GitHub API response', function () {
    $client = makeGitHubClient([
        // GitHub API returns a non-standard default branch
        new Response(200, [], json_encode(['default_branch' => 'develop'])),
        // composer.json on the develop branch
        new Response(200, [], json_encode(['name' => 'vendor/package'])),
    ]);

    expect($client->detectPackageName('user', 'repo'))->toBe('vendor/package');
});

it('detectPackageName falls back to main/master when the GitHub API call fails', function () {
    $client = makeGitHubClient([
        // API call fails
        new RequestException('API error', new Request('GET', 'test')),
        // main branch composer.json succeeds
        new Response(200, [], json_encode(['name' => 'fallback/package'])),
    ]);

    expect($client->detectPackageName('user', 'repo'))->toBe('fallback/package');
});

it('detectPackageName returns null when no branch composer.json contains a name key', function () {
    $client = makeGitHubClient([
        // API → default_branch = 'main' (deduped → branches: [main, master])
        new Response(200, [], json_encode(['default_branch' => 'main'])),
        // main branch: 200 but no 'name' key
        new Response(200, [], json_encode(['description' => 'no name here'])),
        // master branch: also no 'name' key
        new Response(200, [], json_encode(['description' => 'also no name'])),
    ]);

    expect($client->detectPackageName('user', 'repo'))->toBeNull();
});

it('detectPackageName returns null when all HTTP requests fail', function () {
    $client = makeGitHubClient([
        new RequestException('API error', new Request('GET', 'test')),
        new RequestException('Not found', new Request('GET', 'test')),
        new RequestException('Not found', new Request('GET', 'test')),
    ]);

    expect($client->detectPackageName('user', 'repo'))->toBeNull();
});

// ── fetchPullRequest ──────────────────────────────────────────────────────────

it('fetchPullRequest returns title and number on success', function () {
    $client = makeGitHubClient([
        new Response(200, [], json_encode(['title' => 'Fix render pipeline', 'number' => 42])),
    ]);

    $result = $client->fetchPullRequest('owner', 'repo', 42);

    expect($result)->toBe(['title' => 'Fix render pipeline', 'number' => 42]);
});

it('fetchPullRequest returns null when the API call fails', function () {
    $client = makeGitHubClient([
        new RequestException('Not found', new Request('GET', 'test')),
    ]);

    expect($client->fetchPullRequest('owner', 'repo', 42))->toBeNull();
});

it('fetchPullRequest falls back to the given number when the response omits it', function () {
    $client = makeGitHubClient([
        new Response(200, [], json_encode(['title' => 'Improve performance'])),
    ]);

    $result = $client->fetchPullRequest('owner', 'repo', 99);

    expect($result['number'])->toBe(99);
    expect($result['title'])->toBe('Improve performance');
});

// ── downloadPatch ─────────────────────────────────────────────────────────────

it('downloadPatch makes a GET request with the sink option', function () {
    $history = [];
    $client = makeGitHubClient([new Response(200, [], 'PATCH CONTENT')], $history);

    $sink = sys_get_temp_dir().'/darn_test_'.uniqid().'.patch';
    $client->downloadPatch('https://example.com/fix.patch', $sink);

    expect($history[0]['options']['sink'])->toBe($sink);

    @unlink($sink);
});

it('downloadPatch throws on HTTP error', function () {
    $client = makeGitHubClient([
        new RequestException('Download Failed', new Request('GET', 'test')),
    ]);

    $client->downloadPatch('https://example.com/fix.patch', '/tmp/test.patch');
})->throws(\Exception::class);
