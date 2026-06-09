<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Api\DrupalOrgClient;
use Bounteous\Darn\Service\DrupalIssueService;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Helper: build a DrupalIssueService backed by a Guzzle MockHandler.
 */
function makeService(array $responses, ?IOInterface $io = null): DrupalIssueService
{
    return new DrupalIssueService(new DrupalOrgClient(makeGuzzleClient($responses)), $io ?? new NullIO());
}

beforeEach(function () {
    $this->io = $this->createMock(IOInterface::class);
    $this->io->method('write');
    $this->io->method('writeError');
});

// ---------------------------------------------------------------------------
// resolveProjectMachineName
// ---------------------------------------------------------------------------

it('resolves machine name from issue data directly', function () {
    $service = makeService([]);

    $issueData = ['field_project' => ['machine_name' => 'svg_image_field']];
    expect($service->resolveProjectMachineName($issueData))->toBe('svg_image_field');
});

it('falls back to project API when machine name is absent', function () {
    $service = makeService([
        new Response(200, [], json_encode(['field_project_machine_name' => ['machine_name' => 'fallback_module']])),
    ]);

    $issueData = ['field_project' => ['id' => '42']];
    expect($service->resolveProjectMachineName($issueData))->toBe('fallback_module');
});

it('returns null when both machine name and fallback project API fail', function () {
    $service = makeService([
        new RequestException('Server Error', new Request('GET', 'test')),
    ]);

    $issueData = ['field_project' => ['id' => '42']];
    expect($service->resolveProjectMachineName($issueData))->toBeNull();
});

it('returns null when field_project is absent', function () {
    $service = makeService([]);
    expect($service->resolveProjectMachineName([]))->toBeNull();
});

// ---------------------------------------------------------------------------
// getPackageName
// ---------------------------------------------------------------------------

it('maps drupal machine name to drupal/core', function () {
    $service = makeService([]);
    expect($service->getPackageName('drupal'))->toBe('drupal/core');
});

it('maps other machine names to drupal/<name>', function () {
    $service = makeService([]);
    expect($service->getPackageName('views'))->toBe('drupal/views');
    expect($service->getPackageName('svg_image_field'))->toBe('drupal/svg_image_field');
});

// ---------------------------------------------------------------------------
// collectPatches
// ---------------------------------------------------------------------------

it('collects patches from MR results', function () {
    $service = makeService([
        // MR search returns one open MR
        new Response(200, [], json_encode([
            ['iid' => '10', 'title' => 'Fix bug', 'web_url' => 'https://example.com/mr/10', 'updated_at' => '2024-01-01T00:00:00Z', 'sha' => 'abc123'],
        ])),
    ]);

    $issueData = ['field_issue_files' => []];
    $patches = $service->collectPatches($issueData, 'drupal', '12345');

    expect($patches)->toHaveCount(1);
    expect($patches[0]['_is_mr'])->toBeTrue();
    expect($patches[0]['iid'])->toBe('10');
    expect($patches[0]['url'])->toBe('https://example.com/mr/10.patch');
});

it('collects patches from file attachments', function () {
    $service = makeService([
        // MR search: empty
        new Response(200, [], '[]'),
        // File details
        new Response(200, [], json_encode(['name' => 'fix.patch', 'url' => 'http://example.com/fix.patch', 'timestamp' => '1000000000', 'filesize' => 100])),
    ]);

    $issueData = [
        'field_issue_files' => [['file' => ['id' => '99']]],
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '12345');

    expect($patches)->toHaveCount(1);
    expect($patches[0]['name'])->toBe('fix.patch');
});

it('sorts patches newest-first by timestamp', function () {
    $service = makeService([
        // MR search: one MR with old timestamp
        new Response(200, [], json_encode([
            ['iid' => '5', 'title' => 'Old MR', 'web_url' => 'https://example.com/mr/5', 'updated_at' => '2020-01-01T00:00:00Z'],
        ])),
        // File: newer timestamp
        new Response(200, [], json_encode(['name' => 'new.patch', 'url' => 'http://example.com/new.patch', 'timestamp' => '1700000000', 'filesize' => 50])),
    ]);

    $issueData = [
        'field_issue_files' => [['file' => ['id' => '1']]],
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '999');

    expect($patches)->toHaveCount(2);
    // new.patch (timestamp 1700000000) should come before the 2020 MR
    expect($patches[0]['name'])->toBe('new.patch');
    expect($patches[1]['_is_mr'])->toBeTrue();
});

it('assigns correct comment index based on sorted comment IDs', function () {
    $service = makeService([
        new Response(200, [], '[]'), // MR search: empty
        // File has CID 200 — which is comment index 2 after numeric sort of [100, 200]
        new Response(200, [], json_encode(['name' => 'fix.patch', 'url' => 'http://example.com/fix.patch', 'timestamp' => '1000000000', 'filesize' => 0])),
    ]);

    $issueData = [
        'comments' => [['id' => '200'], ['id' => '100']],
        'field_issue_files' => [['file' => ['id' => '1', 'cid' => '200']]],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '9999');

    expect($patches[0]['comment_index'])->toBe(2);
});

it('skips files without an id', function () {
    $service = makeService([
        new Response(200, [], '[]'), // MR search: empty
        // No file API calls expected
    ]);

    $issueData = [
        'field_issue_files' => [['file' => []]], // Missing 'id'
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '111');

    expect($patches)->toBeEmpty();
});

it('skips non-patch file attachments', function () {
    $service = makeService([
        new Response(200, [], '[]'), // MR search: empty
        new Response(200, [], json_encode(['name' => 'readme.txt', 'url' => 'http://example.com/readme.txt', 'timestamp' => '1000000000', 'filesize' => 10])),
    ]);

    $issueData = [
        'field_issue_files' => [['file' => ['id' => '5']]],
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '222');

    expect($patches)->toBeEmpty();
});

it('logs a warning and skips a file when fetching its details fails', function () {
    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->expects($this->once())->method('writeError')
        ->with($this->stringContains('Failed to fetch details for file'));

    $service = makeService([
        new Response(200, [], '[]'), // MR search: empty
        new RequestException('File API Down', new Request('GET', 'test')),
    ], $io);

    $issueData = [
        'field_issue_files' => [['file' => ['id' => '99']]],
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '123');

    expect($patches)->toBeEmpty();
});

it('handles MR fetch failure gracefully and continues with files', function () {
    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->expects($this->once())->method('writeError')->with($this->stringContains('Failed to fetch MRs'));

    $service = makeService([
        new RequestException('GitLab Down', new Request('GET', 'test')), // MR search fails
        new Response(200, [], json_encode(['name' => 'fix.patch', 'url' => 'http://example.com/fix.patch', 'timestamp' => '1000000000', 'filesize' => 0])),
    ], $io);

    $issueData = [
        'field_issue_files' => [['file' => ['id' => '1']]],
        'comments' => [],
    ];
    $patches = $service->collectPatches($issueData, 'drupal', '333');

    // File patch should still be returned despite MR failure
    expect($patches)->toHaveCount(1);
    expect($patches[0]['name'])->toBe('fix.patch');
});
