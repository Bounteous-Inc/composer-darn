<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\DrupalOrgPatchCommand;
use Bounteous\Darn\Patch\PatchValidator;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestDrupalOrgPatchCommand extends DrupalOrgPatchCommand
{
    public IOInterface $ioMock;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function isPatchPackageInstalled(): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_d_org_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);

    $this->command = new TestDrupalOrgPatchCommand();
    $this->command->ioMock = $this->io;
    $this->command->setApplication(new Application());

    $this->composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $this->composer->method('getPackage')->willReturn($package);
    $this->command->setComposer($this->composer);

    // Set up a TestServiceFactory with a passthrough validator so tests don't
    // need a real git repo. Individual tests inject their Guzzle clients via $this->sf.
    $this->sf = new TestServiceFactory($this->io);
    $this->sf->setPatchValidator(new class () extends PatchValidator {
        public function validate(string $filepath, string $packageName, Composer $composer, ?int $depth, IOInterface $io): bool
        {
            return true;
        }
    });
    $this->command->setServiceFactory($this->sf);

    $this->tester = new CommandTester($this->command);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');

    if (is_dir($this->tempDir)) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            @($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
        }
        @rmdir($this->tempDir);
    }
});

it('returns 1 when issue_id argument is not provided', function () {
    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with('<error>The issue_id argument is required.</error>');

    $statusCode = $this->tester->execute([]);

    expect($statusCode)->toBe(1);
});

it('fetches patch from drupal org', function () {
    $issueId = '3151000';
    $fileId = '6338924';

    $container = [];
    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. Issue Details
        new Response(200, [], json_encode([
            'title' => "Test  Issue\nWith   Spaces",
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '123']],
            'field_issue_files' => [
                ['file' => ['id' => $fileId, 'cid' => '123']],
            ],
        ], JSON_THROW_ON_ERROR)),
        // 2. MR Search (Empty)
        new Response(200, [], '[]'),
        // 3. File Details
        new Response(200, [], json_encode([
            'name' => 'fix.patch',
            'url' => 'http://example.com/fix.patch',
            'timestamp' => '1577836800', // 2020-01-01
        ], JSON_THROW_ON_ERROR)),
        // 4. Download Patch
        new Response(200, [], 'PATCH CONTENT'),
    ], $container));

    $this->io->method('write');
    $this->io->method('writeError');
    $this->io->expects($this->once())
        ->method('select')
        ->willReturn('[Comment #1] fix.patch (2020-01-01)');

    $this->tester->execute(['issue_id' => $issueId]);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $expectedKey = "Issue #$issueId: Test Issue With Spaces (fix.patch)";

    $patches = $json['extra']['patches']['drupal/svg_image_field'];
    $patchData = array_values(array_filter($patches, fn ($p) => $p['description'] === $expectedKey))[0] ?? [];

    expect($patchData)->toHaveKey('description');
    expect($patchData['description'])->toBe($expectedKey);
    expect($patchData['url'])->toBe("patches/drupal/svg_image_field/$issueId-1-fix.patch");
    expect($patchData['extra']['issue-tracker-url'])->toBe("https://www.drupal.org/node/$issueId");

    // Verify download request options (sink)
    $downloadRequest = $container[3]; // 4th request
    expect($downloadRequest['options']['sink'])->toBe("patches/drupal/svg_image_field/$issueId-1-fix.patch");
});

it('fetches merge request from gitlab', function () {
    $issueId = '3572050';
    $mrId = '14673';
    $sha = 'abcdef1234567890abcdef1234567890abcdef12';

    $container = [];
    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. Issue Details
        new Response(200, [], json_encode([
            'title' => 'Test Issue',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'drupal'],
            'comments' => [],
            'field_issue_files' => [], // No files
        ], JSON_THROW_ON_ERROR)),
        // 2. MR Search (Found one)
        new Response(200, [], json_encode([
            [
                'iid' => $mrId,
                'title' => 'Fix something',
                'web_url' => "https://git.drupalcode.org/project/drupal/-/merge_requests/$mrId",
                'updated_at' => '2020-01-01T00:00:00Z',
                'sha' => $sha,
            ],
        ], JSON_THROW_ON_ERROR)),
        // 3. Download Patch (MR .patch)
        new Response(200, [], 'MR PATCH CONTENT'),
    ], $container));

    $this->io->method('write');
    $this->io->method('writeError');
    $this->io->expects($this->once())
        ->method('select')
        ->willReturn("[MR !$mrId] Fix something (2020-01-01)");

    $this->tester->execute(['issue_id' => $issueId]);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $expectedKey = "Issue #$issueId: Test Issue (MR !$mrId)";

    $patches = $json['extra']['patches']['drupal/core'];
    $patchData = array_values(array_filter($patches, fn ($p) => $p['description'] === $expectedKey))[0] ?? [];

    expect($patchData)->toHaveKey('description');
    expect($patchData['description'])->toBe($expectedKey);
    $patchPath = $patchData['url'];
    expect($patchPath)->toBe("patches/drupal/core/$issueId-mr-$mrId-".substr($sha, 0, 8).'.patch');
    expect($patchData['extra']['issue-tracker-url'])->toBe("https://www.drupal.org/node/$issueId");

    // Verify download request options (sink)
    $downloadRequest = $container[2]; // 3rd request
    expect($downloadRequest['options']['sink'])->toBe($patchPath);
});

it('handles API failure when fetching issue details', function () {
    $issueId = '12345';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new RequestException('Error Communicating with Server', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Failed to fetch issue data/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('fails when project machine name cannot be resolved', function () {
    $issueId = '12345';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            // field_project missing
        ], JSON_THROW_ON_ERROR)),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Could not determine project machine name/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('resolves project machine name via fallback API call', function () {
    $issueId = '12345';
    $projectId = '555';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details (missing machine_name, has id)
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['id' => $projectId], // No machine_name
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        // Project details (fallback)
        new Response(200, [], json_encode([
            'field_project_machine_name' => ['machine_name' => 'fallback_project'],
        ], JSON_THROW_ON_ERROR)),
        // MR search
        new Response(200, [], '[]'),
    ]));

    $this->io->method('write');
    // Expect error because no patches found, but we want to verify it got past project resolution
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/No .patch files/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('handles fallback API failure when resolving project machine name', function () {
    $issueId = '12345';
    $projectId = '555';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details (missing machine_name, has id)
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['id' => $projectId], // No machine_name
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        // Project details (fallback) - FAIL
        new RequestException('Project API Down', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Could not determine project machine name/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('handles case where no patches are found', function () {
    $issueId = '12345';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details (no files)
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        // MR search (empty)
        new Response(200, [], '[]'),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/No .patch files or Merge Requests found/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('handles patch download failure', function () {
    $issueId = '12345';
    $fileId = '999';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [['file' => ['id' => $fileId]]],
        ], JSON_THROW_ON_ERROR)),
        // MR search
        new Response(200, [], '[]'),
        // File details
        new Response(200, [], json_encode([
            'name' => 'fail.patch',
            'url' => 'http://example.com/fail.patch',
            'timestamp' => '1234567890',
        ], JSON_THROW_ON_ERROR)),
        // Download (Fail)
        new RequestException('Download Failed', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('select')
        ->willReturn('fail.patch (2009-02-13)');
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/Failed to download patch/'));

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('handles failure when fetching merge requests', function () {
    $issueId = '12345';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        // MR search (Fail)
        new RequestException('GitLab Down', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->exactly(2))
        ->method('writeError')
        ->with($this->logicalOr($this->matchesRegularExpression('/Failed to fetch MRs/'), $this->matchesRegularExpression('/No .patch files or Merge Requests found/')));
    // Will eventually fail with "No patches found"
    // Combined expectations above

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('handles failure when fetching file details', function () {
    $issueId = '12345';
    $fileId = '999';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details
        new Response(200, [], json_encode([
            'title' => 'Issue Title',
            'field_issue_status' => '1',
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [['file' => ['id' => $fileId]]],
        ], JSON_THROW_ON_ERROR)),
        // MR search
        new Response(200, [], '[]'),
        // File details (Fail)
        new RequestException('File API Down', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->exactly(2))
        ->method('writeError')
        ->with($this->logicalOr($this->matchesRegularExpression('/Failed to fetch details for file/'), $this->matchesRegularExpression('/No .patch files or Merge Requests found/')));
    // Will eventually fail with "No patches found"
    // Combined expectations above

    $statusCode = $this->tester->execute(['issue_id' => $issueId]);

    expect($statusCode)->toBe(1);
});

it('fetches merge request from gitlab without sha', function () {
    $issueId = '3572050';
    $mrId = '14673';

    $container = [];
    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. Issue Details
        new Response(200, [], json_encode([
            'title' => 'Test Issue',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'drupal'],
            'comments' => [],
            'field_issue_files' => [], // No files
        ], JSON_THROW_ON_ERROR)),
        // 2. MR Search (Found one without SHA)
        new Response(200, [], json_encode([
            [
                'iid' => $mrId,
                'title' => 'Fix something',
                'web_url' => "https://git.drupalcode.org/project/drupal/-/merge_requests/$mrId",
                'updated_at' => '2020-01-01T00:00:00Z',
                // sha is missing
            ],
        ], JSON_THROW_ON_ERROR)),
        // 3. Download Patch (MR .patch)
        new Response(200, [], 'MR PATCH CONTENT'),
    ], $container));

    $this->io->method('write');
    $this->io->method('writeError');
    $this->io->expects($this->once())
        ->method('select')
        ->willReturn("[MR !$mrId] Fix something (2020-01-01)");

    $this->tester->execute(['issue_id' => $issueId]);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);
    $expectedKey = "Issue #$issueId: Test Issue (MR !$mrId)";
    $patches = $json['extra']['patches']['drupal/core'];
    $patchData = array_values(array_filter($patches, fn ($p) => $p['description'] === $expectedKey))[0] ?? [];
    expect($patchData)->toHaveKey('description');
    expect($patchData['description'])->toBe($expectedKey);
    $patchPath = $patchData['url'];
    expect($patchPath)->toBe("patches/drupal/core/$issueId-mr-$mrId.patch");
    expect($patchData['extra']['issue-tracker-url'])->toBe("https://www.drupal.org/node/$issueId");

    // Verify download request options (sink)
    $downloadRequest = $container[2]; // 3rd request
    expect($downloadRequest['options']['sink'])->toBe($patchPath);
});

it('cleans up old patch file when replacing', function () {
    $issueId = '3151000';
    $oldPatchName = 'old.patch';

    // Setup composer.json with old patch
    $json = [
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    "Issue #$issueId: Old Patch" => "patches/drupal/svg_image_field/$oldPatchName",
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    // Create old patch file
    $patchDir = 'patches/drupal/svg_image_field';
    if (! is_dir($patchDir)) {
        mkdir($patchDir, 0777, true);
    }
    file_put_contents("$patchDir/$oldPatchName", 'old content');

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Issue', 'field_project' => ['machine_name' => 'svg_image_field'], 'field_issue_files' => [['file' => ['id' => '999']]]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'), // MRs
        new Response(200, [], json_encode(['name' => 'new.patch', 'url' => 'http://example.com/new.patch', 'timestamp' => 0, 'filesize' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'NEW CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('select')->willReturn('new.patch (42 B) (1970-01-01)');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

    $this->tester->execute(['issue_id' => $issueId]);

    expect(file_exists("$patchDir/$oldPatchName"))->toBeFalse();
    expect(file_exists("$patchDir/$issueId-0-new.patch"))->toBeTrue();

    // Also check composer.json
    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $finalJson = json_decode($content, true);
    $newDescription = "Issue #$issueId: Issue (new.patch)";
    $patches = $finalJson['extra']['patches']['drupal/svg_image_field'];
    $patchData = array_values(array_filter($patches, fn ($p) => $p['description'] === $newDescription))[0] ?? [];

    expect($patchData['url'])->toBe("patches/drupal/svg_image_field/$issueId-0-new.patch");
    expect($patchData['extra']['issue-tracker-url'])->toBe("https://www.drupal.org/node/$issueId");
});

it('handles select returning index instead of value', function () {
    $issueId = '3151000';

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. Issue Details
        new Response(200, [], json_encode([
            'title' => 'Test Issue',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '123']],
            'field_issue_files' => [
                ['file' => ['id' => '1', 'cid' => '123']],
                ['file' => ['id' => '2', 'cid' => '123']],
            ],
        ], JSON_THROW_ON_ERROR)),
        // 2. MR Search (Empty)
        new Response(200, [], '[]'),
        // 3. File Details 1
        new Response(200, [], json_encode(['name' => 'patch1.patch', 'url' => 'http://example.com/1.patch', 'timestamp' => 100], JSON_THROW_ON_ERROR)),
        // 4. File Details 2
        new Response(200, [], json_encode(['name' => 'patch2.patch', 'url' => 'http://example.com/2.patch', 'timestamp' => 200], JSON_THROW_ON_ERROR)),
        // 5. Download Patch
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    // Mock IO to return index '1' (the second patch)
    // Note: patches are sorted by date descending.
    // patch2 (200) will be index 0.
    // patch1 (100) will be index 1.
    $this->io->method('write');
    $this->io->expects($this->once())
        ->method('select')
        ->willReturn(1); // Select index 1 (patch1)

    $this->tester->execute(['issue_id' => $issueId]);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);
    $patches = $json['extra']['patches']['drupal/svg_image_field'];

    // Should have selected patch1 (index 1)
    expect($patches)->toHaveCount(1);
    expect($patches[0]['url'])->toBe("patches/drupal/svg_image_field/$issueId-1-patch1.patch");
});

it('skips cleanup of old patch when user declines confirmation', function () {
    $issueId = '3151000';
    $oldPatchName = 'old.patch';

    $json = [
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    "Issue #$issueId: Old Patch" => "patches/drupal/svg_image_field/$oldPatchName",
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    $patchDir = 'patches/drupal/svg_image_field';
    if (! is_dir($patchDir)) {
        mkdir($patchDir, 0777, true);
    }
    file_put_contents("$patchDir/$oldPatchName", 'old content');

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Issue', 'field_project' => ['machine_name' => 'svg_image_field'], 'field_issue_files' => [['file' => ['id' => '999']]]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode(['name' => 'new.patch', 'url' => 'http://example.com/new.patch', 'timestamp' => 0, 'filesize' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'NEW CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('select')->willReturn('new.patch (42 B) (1970-01-01)');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(false); // User declines

    $this->tester->execute(['issue_id' => $issueId]);

    // Old patch file must still exist since the user declined cleanup
    expect(file_exists("$patchDir/$oldPatchName"))->toBeTrue();
});

it('maps status IDs to human-readable labels', function () {
    expect(DrupalOrgPatchCommand::getStatusLabel(1))->toBe('Active');
    expect(DrupalOrgPatchCommand::getStatusLabel(2))->toBe('Fixed');
    expect(DrupalOrgPatchCommand::getStatusLabel(8))->toBe('Needs review');
    expect(DrupalOrgPatchCommand::getStatusLabel(14))->toBe('RTBC');
    expect(DrupalOrgPatchCommand::getStatusLabel(999))->toContain('Unknown Status');
});

it('handles an unrecognised issue status ID gracefully', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Unknown Status Issue',
            'field_issue_status' => '99', // Not in ISSUE_STATUS_MAP
            'field_project' => ['machine_name' => 'drupal'],
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
    ]));

    $this->io->method('write');
    // Falls through to "No patches found" — not a status-parsing error
    $this->io->expects($this->once())
        ->method('writeError')
        ->with($this->matchesRegularExpression('/No .patch files or Merge Requests found/'));

    $statusCode = $this->tester->execute(['issue_id' => '12345']);
    expect($statusCode)->toBe(1);
});

it('passes formatted file sizes to the patch selection prompt', function () {
    // This test verifies the formatBytes logic through the user-visible selection choices
    // rather than reaching into the private method directly.
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Size Test',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'drupal'],
            'comments' => [['id' => '1']],
            'field_issue_files' => [
                ['file' => ['id' => '1', 'cid' => '1']], // 0 B
                ['file' => ['id' => '2', 'cid' => '1']], // 1.5 KB
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode(['name' => 'zero.patch', 'url' => 'http://example.com/zero.patch', 'timestamp' => '1000000000', 'filesize' => 0], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['name' => 'med.patch', 'url' => 'http://example.com/med.patch', 'timestamp' => '1000000001', 'filesize' => 1536], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('writeError');
    $this->io->expects($this->once())
        ->method('select')
        ->with(
            $this->equalTo('Select a patch to apply:'),
            $this->callback(function (array $choices) {
                $all = implode("\n", $choices);

                return str_contains($all, '0 B') && str_contains($all, '1.5 KB');
            }),
            $this->anything()
        )
        ->willReturn(0);

    $this->tester->execute(['issue_id' => '12345']);
});

it('assigns comment indices to files based on numeric sort order of comment IDs', function () {
    // This test verifies buildCommentIndices logic through the user-visible filename.
    // Comment IDs [200, 100] arrive out-of-order; after numeric sort, 100 → index 1, 200 → index 2.
    // The file has CID 200 so its downloaded filename must contain "-2-".
    $issueId = '9999';

    $container = [];
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Sort Test',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'drupal'],
            'comments' => [['id' => '200'], ['id' => '100']], // Out-of-order
            'field_issue_files' => [
                ['file' => ['id' => '1', 'cid' => '200']], // Should → comment index 2
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode(['name' => 'fix.patch', 'url' => 'http://example.com/fix.patch', 'timestamp' => '1000000000', 'filesize' => 100], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'CONTENT'),
    ], $container));

    $this->io->method('write');
    $this->io->method('writeError');
    $this->io->expects($this->once())->method('select')->willReturn(0);

    $this->tester->execute(['issue_id' => $issueId]);

    // The download sink path is determined by the comment index; CID 200 → index 2
    $sinkPath = $container[3]['options']['sink'];
    expect($sinkPath)->toBe("patches/drupal/core/$issueId-2-fix.patch");
});

it('removes old patch entry from composer.json when replacing', function () {
    $issueId = '3151000';
    $oldPatchName = 'old.patch';
    $oldDescription = "Issue #$issueId: Old Patch";

    // Setup composer.json with old patch
    $json = [
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    [
                        'description' => $oldDescription,
                        'url' => "patches/drupal/svg_image_field/$oldPatchName",
                    ],
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($json));

    // Create old patch file
    $patchDir = 'patches/drupal/svg_image_field';
    if (! is_dir($patchDir)) {
        mkdir($patchDir, 0777, true);
    }
    file_put_contents("$patchDir/$oldPatchName", 'old content');

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Issue', 'field_project' => ['machine_name' => 'svg_image_field'], 'field_issue_files' => [['file' => ['id' => '999']]]], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'), // MRs
        new Response(200, [], json_encode(['name' => 'new.patch', 'url' => 'http://example.com/new.patch', 'timestamp' => 0, 'filesize' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'NEW CONTENT'),
    ]));

    $this->io->method('write');
    $this->io->method('select')->willReturn('new.patch (42 B) (1970-01-01)');
    $this->io->expects($this->once())->method('askConfirmation')->willReturn(true);

    $this->tester->execute(['issue_id' => $issueId]);

    $content = file_get_contents($this->tempComposerJson);
    $finalJson = json_decode($content, true);
    $patches = $finalJson['extra']['patches']['drupal/svg_image_field'];

    // Verify old patch is gone
    $oldPatchFound = array_filter($patches, fn ($p) => $p['description'] === $oldDescription);
    expect($oldPatchFound)->toBeEmpty();

    // Verify new patch is present
    $newPatchFound = array_filter($patches, fn ($p) => str_contains($p['description'], 'new.patch'));
    expect($newPatchFound)->not->toBeEmpty();
});
