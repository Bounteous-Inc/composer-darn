<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Patch\PatchManager;
use Composer\IO\IOInterface;

/**
 * Overrides only readComposerJson so that writeComposerJson uses the real
 * PatchManager logic (JsonFile::write), allowing failure paths to be tested.
 */
class TestPatchManagerNoWriteOverride extends PatchManager
{
    public function readComposerJson(): array
    {
        return [];
    }
}

class TestPatchManager extends PatchManager
{
    public string $mockComposerJsonPath;

    public function readComposerJson(): array
    {
        // Override to read from our mock path instead of Factory::getComposerFile()
        if (! file_exists($this->mockComposerJsonPath)) {
            throw new \RuntimeException("composer.json not found at {$this->mockComposerJsonPath}");
        }

        $content = file_get_contents($this->mockComposerJsonPath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read mock composer.json');
        }

        return json_decode($content, true);
    }

    public function writeComposerJson(array $json): bool
    {
        // Override to write to our mock path
        file_put_contents($this->mockComposerJsonPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_pm_test_'.uniqid();
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir);
    }
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');

    $this->io = $this->createMock(IOInterface::class);

    $this->patchManager = new TestPatchManager($this->io);
    $this->patchManager->mockComposerJsonPath = $this->tempComposerJson;
});

afterEach(function () {
    if (file_exists($this->tempComposerJson)) {
        unlink($this->tempComposerJson);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('reads composer.json correctly', function () {
    $data = ['name' => 'test/package'];
    file_put_contents($this->tempComposerJson, json_encode($data));

    $result = $this->patchManager->readComposerJson();
    expect($result)->toBe($data);
});

it('throws exception when composer.json is missing', function () {
    unlink($this->tempComposerJson);
    $this->patchManager->readComposerJson();
})->throws(\RuntimeException::class, 'composer.json not found');

it('writes composer.json correctly', function () {
    $data = ['name' => 'test/package'];
    $this->patchManager->writeComposerJson($data);

    $content = file_get_contents($this->tempComposerJson);
    expect(json_decode($content, true))->toBe($data);
});

it('adds a new patch to composer.json', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $description = 'Test Patch';

    $this->patchManager->addPatch($filepath, $packageName, $description, null, null);

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches'][$packageName];

    expect($patches)->toHaveCount(1);
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['description'])->toBe($description);
});

it('updates an existing patch in composer.json', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $description = 'Test Patch';

    // Add initial patch
    $this->patchManager->addPatch('old/path.patch', $packageName, $description, null, null);

    // Update it
    $this->patchManager->addPatch($filepath, $packageName, $description, null, null);

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches'][$packageName];

    expect($patches)->toHaveCount(1);
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['description'])->toBe($description);
});

it('adds patch with depth and issue url', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $description = 'Test Patch';
    $issueUrl = 'https://drupal.org/node/123';
    $depth = 2;

    $this->patchManager->addPatch($filepath, $packageName, $description, $issueUrl, $depth);

    $json = $this->patchManager->readComposerJson();
    $patch = $json['extra']['patches'][$packageName][0];

    expect($patch['depth'])->toBe($depth);
    expect($patch['extra']['issue-tracker-url'])->toBe($issueUrl);
});

it('stores ticket in extra when provided', function () {
    $this->patchManager->addPatch('patches/fix.patch', 'drupal/core', 'Fix', null, null, 'JIRA-123');

    $json = $this->patchManager->readComposerJson();
    $patch = $json['extra']['patches']['drupal/core'][0];

    expect($patch['extra']['ticket'])->toBe('JIRA-123');
});

it('omits ticket from extra when not provided', function () {
    $this->patchManager->addPatch('patches/fix.patch', 'drupal/core', 'Fix', null, null);

    $json = $this->patchManager->readComposerJson();
    $patch = $json['extra']['patches']['drupal/core'][0];

    expect($patch)->not->toHaveKey('extra');
});

it('handles legacy patch format correctly', function () {
    // Setup legacy format: "Description": "URL"
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Legacy Patch' => 'patches/legacy.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    // Add new patch
    $this->patchManager->addPatch('patches/new.patch', 'drupal/core', 'New Patch', null, null);

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];

    expect($patches)->toHaveCount(2);
    // Should convert legacy to object format
    expect($patches[0]['description'])->toBe('Legacy Patch');
    expect($patches[0]['url'])->toBe('patches/legacy.patch');
    expect($patches[1]['description'])->toBe('New Patch');
});

it('sorts patches alphabetically by description', function () {
    // Pre-populate with unsorted patches
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Z Patch' => 'path/to/z',
                    'A Patch' => 'path/to/a',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    // Add a new patch that should be inserted in the middle
    $this->patchManager->addPatch('path/to/m', 'drupal/core', 'M Patch', null, null);

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];

    expect($patches)->toHaveCount(3);
    expect($patches[0]['description'])->toBe('A Patch');
    expect($patches[1]['description'])->toBe('M Patch');
    expect($patches[2]['description'])->toBe('Z Patch');
});

it('removes a patch from composer.json by description', function () {
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Keep this', 'url' => 'patches/keep.patch'],
                    ['description' => 'Remove this', 'url' => 'patches/remove.patch'],
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->removePatch('drupal/core', 'Remove this');

    expect($result)->toBeTrue();

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];

    expect($patches)->toHaveCount(1);
    expect($patches[0]['description'])->toBe('Keep this');
});

it('removes a legacy-format patch by description', function () {
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Legacy Keep' => 'patches/keep.patch',
                    'Legacy Remove' => 'patches/remove.patch',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->removePatch('drupal/core', 'Legacy Remove');

    expect($result)->toBeTrue();

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];

    expect($patches)->toHaveCount(1);
    expect($patches[0]['description'])->toBe('Legacy Keep');
});

it('removes the package key entirely when last patch is removed', function () {
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Only patch', 'url' => 'patches/only.patch'],
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $this->patchManager->removePatch('drupal/core', 'Only patch');

    $json = $this->patchManager->readComposerJson();

    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
});

it('returns false when the description is not found in removePatch', function () {
    $initialJson = [
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'A patch', 'url' => 'patches/a.patch'],
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->removePatch('drupal/core', 'Non-existent description');

    expect($result)->toBeFalse();
});

it('returns false when the package is not found in removePatch', function () {
    file_put_contents($this->tempComposerJson, json_encode(['extra' => ['patches' => []]]));

    $result = $this->patchManager->removePatch('non/existent', 'Any description');

    expect($result)->toBeFalse();
});

it('replaces an entry under the same package in replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Keep this', 'url' => 'patches/keep.patch'],
                ['description' => 'Old description', 'url' => 'patches/old.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->replacePatch(
        'drupal/core',
        'Old description',
        'patches/new.patch',
        'drupal/core',
        'New description',
        'https://drupal.org/node/123',
        null
    );

    expect($result)->toBeTrue();
    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];
    expect($patches)->toHaveCount(2);
    $byDesc = array_column($patches, null, 'description');
    expect($byDesc['New description']['url'])->toBe('patches/new.patch');
    expect($byDesc['New description']['extra']['issue-tracker-url'])->toBe('https://drupal.org/node/123');
    expect($byDesc)->toHaveKey('Keep this');
});

it('moves entry to a different package in replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/old_module' => [
                ['description' => 'Old description', 'url' => 'patches/old.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->replacePatch(
        'drupal/old_module',
        'Old description',
        'patches/new.patch',
        'drupal/new_module',
        'New description',
        null,
        null
    );

    expect($result)->toBeTrue();
    $json = $this->patchManager->readComposerJson();
    expect($json['extra']['patches'])->not->toHaveKey('drupal/old_module');
    $patches = $json['extra']['patches']['drupal/new_module'];
    expect($patches)->toHaveCount(1);
    expect($patches[0]['description'])->toBe('New description');
    expect($patches[0]['url'])->toBe('patches/new.patch');
});

it('removes old package key when last entry is replaced by replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Only patch', 'url' => 'patches/only.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $this->patchManager->replacePatch(
        'drupal/core',
        'Only patch',
        'patches/new.patch',
        'drupal/other',
        'New patch',
        null,
        null
    );

    $json = $this->patchManager->readComposerJson();
    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
    expect($json['extra']['patches']['drupal/other'][0]['description'])->toBe('New patch');
});

it('returns false when old package not found in replacePatch', function () {
    file_put_contents($this->tempComposerJson, json_encode(['extra' => ['patches' => []]]));

    $result = $this->patchManager->replacePatch(
        'non/existent',
        'Old description',
        'patches/new.patch',
        'non/existent',
        'New description',
        null,
        null
    );

    expect($result)->toBeFalse();
});

it('returns false when old description not found in replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Existing patch', 'url' => 'patches/existing.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $result = $this->patchManager->replacePatch(
        'drupal/core',
        'Non-existent description',
        'patches/new.patch',
        'drupal/core',
        'New description',
        null,
        null
    );

    expect($result)->toBeFalse();
});

it('preserves depth and ticket in replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Old', 'url' => 'patches/old.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $this->patchManager->replacePatch(
        'drupal/core',
        'Old',
        'patches/new.patch',
        'drupal/core',
        'New',
        null,
        2,
        'PROJ-456'
    );

    $json = $this->patchManager->readComposerJson();
    $patch = $json['extra']['patches']['drupal/core'][0];
    expect($patch['depth'])->toBe(2);
    expect($patch['extra']['ticket'])->toBe('PROJ-456');
});

it('sorts alphabetically after replacePatch', function () {
    $initialJson = [
        'extra' => ['patches' => [
            'drupal/core' => [
                ['description' => 'Beta patch', 'url' => 'patches/beta.patch'],
                ['description' => 'Old alpha patch', 'url' => 'patches/old.patch'],
            ],
        ]],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $this->patchManager->replacePatch(
        'drupal/core',
        'Old alpha patch',
        'patches/alpha.patch',
        'drupal/core',
        'Alpha patch',
        null,
        null
    );

    $json = $this->patchManager->readComposerJson();
    $patches = $json['extra']['patches']['drupal/core'];
    expect($patches[0]['description'])->toBe('Alpha patch');
    expect($patches[1]['description'])->toBe('Beta patch');
});

it('logs an error and returns false when writeComposerJson cannot write to the path', function () {
    // Create a regular FILE at the path that will be used as the parent directory.
    // JsonFile::write() detects that the parent exists but is not a directory and throws.
    $fileActingAsDir = sys_get_temp_dir().'/darn_not_a_dir_'.uniqid();
    file_put_contents($fileActingAsDir, 'not-a-directory');
    $invalidPath = $fileActingAsDir.'/composer.json';
    putenv('COMPOSER='.$invalidPath);

    try {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())
            ->method('writeError')
            ->with($this->stringContains('Failed to write'));

        $manager = new TestPatchManagerNoWriteOverride($io);
        $result = $manager->writeComposerJson(['name' => 'test/project']);

        expect($result)->toBeFalse();
    } finally {
        @unlink($fileActingAsDir);
        putenv('COMPOSER'); // Always restore to avoid leaking state
    }
});
