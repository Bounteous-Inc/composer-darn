<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\RemoveCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestRemoveCommand extends RemoveCommand
{
    public IOInterface $ioMock;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Bypass Composer\Command\BaseCommand::initialize() which would eagerly
        // parse composer.json, throwing before execute() can handle error conditions.
        $this->input = $input;
        $this->output = $output;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Returns a minimal composer.json payload with the given patches structure.
 *
 * @param  array<string, mixed>  $patches
 */
function makeComposerJson(array $patches): string
{
    return json_encode(['extra' => ['patches' => $patches]]);
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_remove_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->command = new TestRemoveCommand();
    $this->command->ioMock = $this->io;
    $this->command->setApplication(new Application());
    $this->sf = new TestServiceFactory($this->io);
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

// ---------------------------------------------------------------------------
// Non-interactive (argument-driven) path
// ---------------------------------------------------------------------------

it('removes a patch non-interactively when both arguments are provided', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [
            ['description' => 'Fix bug', 'url' => 'patches/fix.patch'],
        ],
    ]));

    $this->io->method('write');

    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug'],
        ['interactive' => false]
    );

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
});

it('errors in non-interactive mode when description is missing', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('non-interactive mode'));

    $result = $this->tester->execute(['package' => 'drupal/core'], ['interactive' => false]);

    expect($result)->toBe(1);
});

it('errors in non-interactive mode when no arguments are provided', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('non-interactive mode'));

    $result = $this->tester->execute([], ['interactive' => false]);

    expect($result)->toBe(1);
});

it('errors when the specified package does not exist', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('No patches found for package'));

    $result = $this->tester->execute(
        ['package' => 'drupal/views', 'description' => 'Fix bug'],
        ['interactive' => false]
    );

    expect($result)->toBe(1);
});

it('errors when the description does not match any patch', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('No patch found with description'));

    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Nonexistent description'],
        ['interactive' => false]
    );

    expect($result)->toBe(1);
});

it('reports no patches when composer.json has no patches key', function () {
    file_put_contents($this->tempComposerJson, json_encode(['name' => 'test/project']));

    $this->io->expects($this->once())->method('write')
        ->with($this->matchesRegularExpression('/No patches found/'));

    // Provide args so the non-interactive guard is satisfied; empty patches are reached next.
    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug'],
        ['interactive' => false]
    );

    expect($result)->toBe(0);
});

// ---------------------------------------------------------------------------
// --delete flag (no prompt)
// ---------------------------------------------------------------------------

it('deletes the patch file when --delete is passed', function () {
    mkdir('patches', 0777, true);
    file_put_contents('patches/fix.patch', 'content');

    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');

    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug', '--delete' => true],
        ['interactive' => false]
    );

    expect($result)->toBe(0);
    expect(file_exists('patches/fix.patch'))->toBeFalse();
});

it('skips the delete step when the file is already absent with --delete', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/missing.patch']],
    ]));

    $this->io->method('write');

    // Should not throw even though the file does not exist
    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug', '--delete' => true],
        ['interactive' => false]
    );

    expect($result)->toBe(0);
});

// ---------------------------------------------------------------------------
// Interactive: index-based selection
// ---------------------------------------------------------------------------

it('removes a patch selected by index in interactive mode', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [
            ['description' => 'Fix bug', 'url' => 'patches/fix.patch'],
            ['description' => 'Other patch', 'url' => 'patches/other.patch'],
        ],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('1');
    $this->io->method('askConfirmation')->willReturn(true);

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    $descriptions = array_column($json['extra']['patches']['drupal/core'], 'description');
    expect($descriptions)->not->toContain('Fix bug');
    expect($descriptions)->toContain('Other patch');
});

it('removes all patches when * is entered', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [
            ['description' => 'Patch A', 'url' => 'patches/a.patch'],
            ['description' => 'Patch B', 'url' => 'patches/b.patch'],
        ],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('*');
    $this->io->method('askConfirmation')->willReturn(true);

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
});

it('cancels when blank input is entered at the selection prompt', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('');

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);

    // composer.json should be unchanged
    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches']['drupal/core'])->toHaveCount(1);
});

it('errors on an invalid index', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('99');
    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('Invalid selection'));

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(1);
});

it('cancels when the removal confirmation is declined', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('1');
    $this->io->method('askConfirmation')->willReturn(false);

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);

    // composer.json should be unchanged
    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches']['drupal/core'])->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Interactive: no package argument → package select() first
// ---------------------------------------------------------------------------

it('selects the package interactively when no argument is provided', function () {
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
        'drupal/views' => [['description' => 'Views fix', 'url' => 'patches/views.patch']],
    ]));

    $this->io->method('write');
    // select() returns '0' (index) → resolves to first package alphabetically (drupal/core)
    $this->io->method('select')->willReturn('0');
    $this->io->method('ask')->willReturn('1');
    $this->io->method('askConfirmation')->willReturn(true);

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->not->toHaveKey('drupal/core');
    expect($json['extra']['patches'])->toHaveKey('drupal/views');
});

// ---------------------------------------------------------------------------
// Interactive: file deletion prompt
// ---------------------------------------------------------------------------

it('asks to delete the file and deletes it when confirmed', function () {
    mkdir('patches', 0777, true);
    file_put_contents('patches/fix.patch', 'content');

    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('1');
    // First askConfirmation: "Remove patch?" → true
    // Second askConfirmation: "Also delete file?" → true
    $this->io->method('askConfirmation')->willReturnOnConsecutiveCalls(true, true);

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);
    expect(file_exists('patches/fix.patch'))->toBeFalse();
});

it('keeps the file when the delete confirmation is declined', function () {
    mkdir('patches', 0777, true);
    file_put_contents('patches/fix.patch', 'content');

    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/fix.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('1');
    // First askConfirmation: "Remove patch?" → true
    // Second askConfirmation: "Also delete file?" → false
    $this->io->method('askConfirmation')->willReturnOnConsecutiveCalls(true, false);

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);
    expect(file_exists('patches/fix.patch'))->toBeTrue();
});

it('does not prompt for file deletion when the file is missing from disk', function () {
    // The patch is registered but the file is not on disk
    file_put_contents($this->tempComposerJson, makeComposerJson([
        'drupal/core' => [['description' => 'Fix bug', 'url' => 'patches/missing.patch']],
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('1');
    // Only one confirmation: "Remove patch?" — no file-delete prompt
    $this->io->expects($this->once())->method('askConfirmation')
        ->with($this->stringContains('Remove'));

    $result = $this->tester->execute(['package' => 'drupal/core']);

    expect($result)->toBe(0);
});

// ---------------------------------------------------------------------------
// Error paths
// ---------------------------------------------------------------------------

it('fails gracefully when composer.json is missing', function () {
    $this->io->expects($this->once())->method('writeError')
        ->with($this->matchesRegularExpression('/composer.json not found/'));

    // Provide args so the non-interactive guard is satisfied; the JSON read fails next.
    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug'],
        ['interactive' => false]
    );

    expect($result)->toBe(1);
});

it('fails gracefully when composer.json is invalid', function () {
    file_put_contents($this->tempComposerJson, '{invalid');

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('does not contain valid JSON'));

    // Provide args so the non-interactive guard is satisfied; the JSON read fails next.
    $result = $this->tester->execute(
        ['package' => 'drupal/core', 'description' => 'Fix bug'],
        ['interactive' => false]
    );

    expect($result)->toBe(1);
});
