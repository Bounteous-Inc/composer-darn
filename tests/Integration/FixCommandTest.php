<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\FixCommand;
use Bounteous\Darn\Patch\PatchManagerInterface;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Tester\CommandTester;

class TestFixCommand extends FixCommand
{
    public IOInterface $ioMock;

    public string $overridePatchesDir = 'patches';

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function getPatchesDirectory(?string $override = null): string
    {
        return $override ?? $this->overridePatchesDir;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_fix_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->patchesDir = $this->tempDir.'/patches';
    mkdir($this->patchesDir);
    $this->composerJsonPath = $this->tempDir.'/composer.json';
    file_put_contents($this->composerJsonPath, '{}');

    putenv('COMPOSER='.$this->composerJsonPath);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);

    $this->io = $this->createMock(IOInterface::class);

    $this->composer = $this->createMock(Composer::class);
    $package = $this->createMock(RootPackageInterface::class);
    $package->method('getExtra')->willReturn([]);
    $this->composer->method('getPackage')->willReturn($package);

    $this->sf = makeTestSf($this->io);

    $this->command = new TestFixCommand();
    $this->command->ioMock = $this->io;
    $this->command->overridePatchesDir = 'patches';
    $this->command->setApplication(new Application());
    $this->command->setComposer($this->composer);
    $this->command->setServiceFactory($this->sf);

    $this->tester = new CommandTester($this->command);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

it('reports no patches when composer.json has no patches section', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);
});

it('skips patches with local file paths and leaves composer.json unchanged', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'A local patch', 'url' => 'patches/drupal/core/local.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['drupal/core'][0]['description'])->toBe('A local patch');
});

it('skips patches with no URL and leaves composer.json unchanged', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'No URL patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['drupal/core'][0]['description'])->toBe('No URL patch');
});

it('warns and leaves composer.json unchanged for unrecognized gitlab.com URLs', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('unrecognized URL'));

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'some/package' => [
                    ['description' => 'GitLab patch', 'url' => 'https://gitlab.com/some/project/-/merge_requests/5.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['some/package'][0]['description'])->toBe('GitLab patch');
});

it('warns and leaves entry unchanged on drupal.org API failure', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('could not fetch issue'));

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Old patch', 'url' => 'https://www.drupal.org/files/issues/2024-01-15/3151000-5-fix.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new RequestException('Connection failed', new Request('GET', 'https://www.drupal.org')),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['drupal/core'][0]['description'])->toBe('Old patch');
});

it('normalizes a drupal.org patch entry', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $issueId = '3151000';
    $fileId = '6338924';
    $remoteUrl = "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    ['description' => 'Old description', 'url' => $remoteUrl],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/drupal', 0777, true);
    mkdir($this->patchesDir.'/drupal/svg_image_field', 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. Issue details
        new Response(200, [], json_encode([
            'title' => 'Fix the rendering bug',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '100']],
            'field_issue_files' => [
                ['file' => ['id' => $fileId, 'cid' => '100']],
            ],
        ], JSON_THROW_ON_ERROR)),
        // 2. MR search (empty)
        new Response(200, [], '[]'),
        // 3. File details — URL matches the entry URL
        new Response(200, [], json_encode([
            'name' => 'fix.patch',
            'url' => $remoteUrl,
            'timestamp' => '1577836800',
            'filesize' => 1024,
        ], JSON_THROW_ON_ERROR)),
        // 4. Patch download
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patches = $json['extra']['patches']['drupal/svg_image_field'];
    expect($patches)->toHaveCount(1);

    $patch = $patches[0];
    expect($patch['description'])->toBe("Issue #{$issueId}: Fix the rendering bug (fix.patch)");
    expect($patch['url'])->toBe("patches/drupal/svg_image_field/{$issueId}-1-fix.patch");
    expect($patch['extra']['issue-tracker-url'])->toBe("https://www.drupal.org/node/{$issueId}");
});

it('preserves depth and ticket when normalizing', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $issueId = '3151000';
    $remoteUrl = "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    [
                        'description' => 'Old description',
                        'url' => $remoteUrl,
                        'depth' => 1,
                        'extra' => ['ticket' => 'PROJ-123'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/drupal', 0777, true);
    mkdir($this->patchesDir.'/drupal/svg_image_field', 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Fix the rendering bug',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '100']],
            'field_issue_files' => [
                ['file' => ['id' => '999', 'cid' => '100']],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        new Response(200, [], json_encode([
            'name' => 'fix.patch',
            'url' => $remoteUrl,
            'timestamp' => '1577836800',
            'filesize' => 512,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $this->tester->execute([]);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches']['drupal/svg_image_field'][0];

    expect($patch['depth'])->toBe(1);
    expect($patch['extra']['ticket'])->toBe('PROJ-123');
});

it('does not write changes or download files in dry-run mode', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $issueId = '3151000';
    $remoteUrl = "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";
    $originalJson = json_encode([
        'extra' => [
            'patches' => [
                'drupal/svg_image_field' => [
                    ['description' => 'Old description', 'url' => $remoteUrl],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    file_put_contents($this->composerJsonPath, $originalJson);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // Issue details — only metadata fetch, no download
        new Response(200, [], json_encode([
            'title' => 'Fix the rendering bug',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [],
            'field_issue_files' => [],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
    ]));

    $status = $this->tester->execute(['--dry-run' => true]);

    expect($status)->toBe(0);

    // composer.json must be unchanged
    expect(file_get_contents($this->composerJsonPath))->toBe($originalJson);

    // No patch file should have been created
    expect(is_dir($this->patchesDir.'/drupal/svg_image_field'))->toBeFalse();
});

it('matches a drupal.org patch via path-only url fallback (http vs https)', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $issueId = '3151000';
    $entryUrl = "http://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";
    $apiUrl = "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => ['patches' => [
            'drupal/svg_image_field' => [
                ['description' => 'Old description', 'url' => $entryUrl],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/drupal', 0777, true);
    mkdir($this->patchesDir.'/drupal/svg_image_field', 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Fix the rendering bug',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '100']],
            'field_issue_files' => [['file' => ['id' => '12345', 'cid' => '100']]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        // API returns https:// but the composer entry uses http:// — path-only fallback matches
        new Response(200, [], json_encode([
            'name' => 'fix.patch',
            'url' => $apiUrl,
            'timestamp' => '1577836800',
            'filesize' => 1024,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches']['drupal/svg_image_field'][0];
    expect($patch['description'])->toBe("Issue #{$issueId}: Fix the rendering bug (fix.patch)");
    expect($patch['url'])->toBe("patches/drupal/svg_image_field/{$issueId}-1-fix.patch");
});

it('warns and skips entries with unrecognized URLs', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('unrecognized URL'));

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                'some/package' => [
                    ['description' => 'CDN patch', 'url' => 'https://example.com/patches/some.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches']['some/package'][0]['description'])->toBe('CDN patch');
});

it('normalizes a github pull request patch entry', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $packageName = 'some/package';
    $prUrl = 'https://github.com/acme/widget/pull/42.patch';

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old GitHub patch', 'url' => $prUrl],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/'.$packageName, 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        // 1. PR fetch
        new Response(200, [], json_encode(['title' => 'Add widget support', 'number' => 42], JSON_THROW_ON_ERROR)),
        // 2. Patch download
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches'][$packageName][0];

    expect($patch['description'])->toBe('PR #42: Add widget support (acme/widget)');
    expect($patch['url'])->toBe("patches/{$packageName}/acme-widget-pr-42.patch");
    expect($patch['extra']['issue-tracker-url'])->toBe('https://github.com/acme/widget/pull/42');
});

it('uses fallback description when GitHub API call fails silently', function () {
    // GitHubClient::fetchPullRequest() catches all exceptions and returns null.
    // FixCommand treats a null PR identically to an empty title: uses "Patch from {url}".
    $this->io->method('write');
    $this->io->method('writeError');

    $packageName = 'some/package';

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old GitHub patch', 'url' => 'https://github.com/acme/widget/pull/42.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/'.$packageName, 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new RequestException('Connection failed', new Request('GET', 'https://api.github.com')),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches'][$packageName][0]['description'])->toBe('Patch from https://github.com/acme/widget/pull/42');
});

it('shows dry-run diff for a GitHub PR entry without downloading', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $originalJson = json_encode([
        'extra' => [
            'patches' => [
                'some/package' => [
                    ['description' => 'Old GitHub patch', 'url' => 'https://github.com/acme/widget/pull/42.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    file_put_contents($this->composerJsonPath, $originalJson);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Add widget support', 'number' => 42], JSON_THROW_ON_ERROR)),
        // No second response: download must not be called in dry-run
    ]));

    $status = $this->tester->execute(['--dry-run' => true]);

    expect($status)->toBe(0);
    expect(file_get_contents($this->composerJsonPath))->toBe($originalJson);
    expect(is_dir($this->patchesDir.'/some/package'))->toBeFalse();
});

it('uses fallback description when GitHub PR title is empty', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $packageName = 'some/package';

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old GitHub patch', 'url' => 'https://github.com/acme/widget/pull/42.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/'.$packageName, 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => '', 'number' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches'][$packageName][0];
    expect($patch['description'])->toBe('Patch from https://github.com/acme/widget/pull/42');
});

it('uses bare issue ID filename when no patch file matches in issue', function () {
    $this->io->method('write');
    $this->io->method('writeError');

    $issueId = '3151000';
    $entryUrl = "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-5-fix.patch";

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => ['patches' => [
            'drupal/svg_image_field' => [
                ['description' => 'Old description', 'url' => $entryUrl],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/drupal', 0777, true);
    mkdir($this->patchesDir.'/drupal/svg_image_field', 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode([
            'title' => 'Fix the rendering bug',
            'field_issue_status' => '8',
            'field_project' => ['machine_name' => 'svg_image_field'],
            'comments' => [['id' => '100']],
            'field_issue_files' => [['file' => ['id' => '12345', 'cid' => '100']]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], '[]'),
        // File URL does not match the entry URL — path-only fallback also fails
        new Response(200, [], json_encode([
            'name' => 'different.patch',
            'url' => "https://www.drupal.org/files/issues/2024-01-15/{$issueId}-6-different.patch",
            'timestamp' => '1577836800',
            'filesize' => 1024,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    $patch = $json['extra']['patches']['drupal/svg_image_field'][0];
    expect($patch['description'])->toBe("Issue #{$issueId}: Fix the rendering bug");
    expect($patch['url'])->toBe("patches/drupal/svg_image_field/{$issueId}.patch");
});

it('returns 1 when composer.json cannot be parsed', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())->method('writeError');

    file_put_contents($this->composerJsonPath, 'not valid json {{{');

    $this->sf->setGuzzleClient(makeGuzzleClient([]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(1);
});

it('warns when the patch download fails', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('download failed'));

    $packageName = 'some/package';

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old GitHub patch', 'url' => 'https://github.com/acme/widget/pull/42.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    mkdir($this->patchesDir.'/'.$packageName, 0777, true);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Add widget support', 'number' => 42], JSON_THROW_ON_ERROR)),
        new RequestException('Network error', new Request('GET', 'https://github.com')),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches'][$packageName][0]['description'])->toBe('Old GitHub patch');
});

it('warns and discards the file when patch validation fails', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('patch validation failed'));

    $packageName = 'some/package';

    file_put_contents($this->composerJsonPath, json_encode([
        'extra' => [
            'patches' => [
                $packageName => [
                    ['description' => 'Old GitHub patch', 'url' => 'https://github.com/acme/widget/pull/42.patch'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->sf->setPatchValidator(new class () extends \Bounteous\Darn\Patch\PatchValidator {
        public function validate(string $filepath, string $packageName, \Composer\Composer $composer, ?int $depth, \Composer\IO\IOInterface $io): bool
        {
            return false;
        }
    });

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Add widget support', 'number' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);

    $json = json_decode(file_get_contents($this->composerJsonPath), true);
    expect($json['extra']['patches'][$packageName][0]['description'])->toBe('Old GitHub patch');
    expect(file_exists($this->patchesDir.'/'.$packageName.'/acme-widget-pr-42.patch'))->toBeFalse();
});

it('warns and discards the file when composer.json update fails', function () {
    $this->io->method('write');
    $this->io->expects($this->atLeastOnce())
        ->method('writeError')
        ->with($this->stringContains('could not update composer.json'));

    $packageName = 'some/package';
    $prUrl = 'https://github.com/acme/widget/pull/42.patch';

    $mockPm = $this->createMock(PatchManagerInterface::class);
    $mockPm->method('readComposerJson')->willReturn([
        'extra' => ['patches' => [
            $packageName => [
                ['description' => 'Old GitHub patch', 'url' => $prUrl],
            ],
        ]],
    ]);
    $mockPm->method('replacePatch')->willReturn(false);
    $this->sf->setPatchManager($mockPm);

    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], json_encode(['title' => 'Add widget support', 'number' => 42], JSON_THROW_ON_ERROR)),
        new Response(200, [], 'PATCH CONTENT'),
    ]));

    $status = $this->tester->execute([]);

    expect($status)->toBe(0);
    expect(file_exists($this->patchesDir.'/'.$packageName.'/acme-widget-pr-42.patch'))->toBeFalse();
});
