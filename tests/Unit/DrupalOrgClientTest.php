<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Api\DrupalOrgClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function makeClient(array $responses, array &$history = []): DrupalOrgClient
{
    return new DrupalOrgClient(makeGuzzleClient($responses, $history));
}

it('fetchIssueData returns decoded array on success', function () {
    $payload = ['title' => 'Test Issue', 'field_issue_status' => '8'];
    $client = makeClient([new Response(200, [], json_encode($payload))]);

    expect($client->fetchIssueData('123'))->toBe($payload);
});

it('fetchIssueData throws on HTTP error', function () {
    $client = makeClient([
        new RequestException('Server Error', new Request('GET', 'test')),
    ]);

    $client->fetchIssueData('123');
})->throws(\Exception::class);

it('fetchProjectDetails returns decoded array on success', function () {
    $payload = ['field_project_machine_name' => ['machine_name' => 'drupal']];
    $client = makeClient([new Response(200, [], json_encode($payload))]);

    expect($client->fetchProjectDetails('555'))->toBe($payload);
});

it('fetchProjectDetails returns null on HTTP error', function () {
    $client = makeClient([
        new RequestException('Not Found', new Request('GET', 'test')),
    ]);

    expect($client->fetchProjectDetails('999'))->toBeNull();
});

it('fetchMergeRequests returns array of MRs on success', function () {
    $payload = [['iid' => 42, 'title' => 'Fix bug', 'web_url' => 'https://git.drupalcode.org/mr/42']];
    $client = makeClient([new Response(200, [], json_encode($payload))]);

    expect($client->fetchMergeRequests('drupal', '3000000'))->toBe($payload);
});

it('fetchMergeRequests throws on HTTP error', function () {
    $client = makeClient([
        new RequestException('GitLab Down', new Request('GET', 'test')),
    ]);

    $client->fetchMergeRequests('drupal', '3000000');
})->throws(\Exception::class);

it('fetchFileDetails returns decoded array on success', function () {
    $payload = ['name' => 'fix.patch', 'url' => 'http://example.com/fix.patch'];
    $client = makeClient([new Response(200, [], json_encode($payload))]);

    expect($client->fetchFileDetails('6338924'))->toBe($payload);
});

it('fetchFileDetails throws on HTTP error', function () {
    $client = makeClient([
        new RequestException('File API Down', new Request('GET', 'test')),
    ]);

    $client->fetchFileDetails('6338924');
})->throws(\Exception::class);

it('downloadFile makes GET request with sink option', function () {
    $history = [];
    $client = makeClient([new Response(200, [], 'PATCH CONTENT')], $history);

    $sink = sys_get_temp_dir().'/darn_test_'.uniqid().'.patch';
    $client->downloadFile('http://example.com/fix.patch', $sink);

    expect($history[0]['options']['sink'])->toBe($sink);

    @unlink($sink);
});

it('downloadFile throws on HTTP error', function () {
    $client = makeClient([
        new RequestException('Download Failed', new Request('GET', 'test')),
    ]);

    $client->downloadFile('http://example.com/fix.patch', '/tmp/test.patch');
})->throws(\Exception::class);

it('fetchIssueData calls the correct Drupal.org API endpoint', function () {
    $history = [];
    $client = makeClient([new Response(200, [], '{"title":"T"}')], $history);
    $client->fetchIssueData('3151000');

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://www.drupal.org/api-d7/node/3151000.json');
});

it('fetchMergeRequests calls the correct GitLab endpoint', function () {
    $history = [];
    $client = makeClient([new Response(200, [], '[]')], $history);
    $client->fetchMergeRequests('drupal', '3151000');

    $uri = (string) $history[0]['request']->getUri();
    expect($uri)->toContain('git.drupalcode.org');
    expect($uri)->toContain('project%2Fdrupal');
    expect($uri)->toContain('3151000');
});

it('fetchProjectDetails calls the correct Drupal.org API endpoint', function () {
    $history = [];
    $client = makeClient([new Response(200, [], json_encode(['field_project_machine_name' => ['machine_name' => 'drupal']]))], $history);
    $client->fetchProjectDetails('42');

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://www.drupal.org/api-d7/node/42.json');
});

it('fetchFileDetails calls the correct Drupal.org file API endpoint', function () {
    $history = [];
    $client = makeClient([new Response(200, [], json_encode(['name' => 'fix.patch']))], $history);
    $client->fetchFileDetails('6338924');

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://www.drupal.org/api-d7/file/6338924.json');
});
