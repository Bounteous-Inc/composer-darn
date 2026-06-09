<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\DirectPatchCommand;
use Bounteous\Darn\Patch\PatchValidator;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

/**
 * PatchValidator that always passes — keeps tests focused on download and
 * registration logic without needing real patch files or git installed.
 */
class DirectPassthroughPatchValidator extends PatchValidator
{
    public function validate(
        string $filepath,
        string $packageName,
        Composer $composer,
        ?int $depth,
        IOInterface $io
    ): bool {
        return true;
    }
}

class TestDirectPatchCommand extends DirectPatchCommand
{
    public IOInterface $ioMock;

    public ?Composer $composerMock = null;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Bypass BasePatchCommand::initialize() which checks for cweagans/composer-patches
        // and calls parent::initialize() which boots the full Composer application.
        $this->input = $input;
        $this->output = $output;
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // Skip the cweagans/composer-patches installation prompt.
    }

    public function requireComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): Composer
    {
        return $this->composerMock ?? parent::requireComposer($disablePlugins, $disableScripts);
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_direct_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    file_put_contents($this->tempComposerJson, '{}');
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->composer = $this->createMock(Composer::class);
    $this->io = $this->createMock(IOInterface::class);

    $this->command = new TestDirectPatchCommand();
    $this->command->ioMock = $this->io;
    $this->command->composerMock = $this->composer;
    $this->command->setApplication(new Application());

    $this->sf = new TestServiceFactory($this->io);
    $this->sf->setPatchValidator(new DirectPassthroughPatchValidator());
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

it('downloads a .patch URL and registers it in composer.json', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'patch content'),
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('My description');

    $result = $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(0);

    $patchFile = 'patches/drupal/core/fix.patch';
    expect(file_exists($patchFile))->toBeTrue();

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->toHaveKey('drupal/core');
});

it('downloads a .diff URL and registers it in composer.json', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'diff content'),
    ]));

    $this->io->method('write');
    $this->io->method('ask')->willReturn('Diff description');

    $result = $this->tester->execute([
        'source' => 'https://example.com/files/change.diff',
        '--package' => 'drupal/views',
    ]);

    expect($result)->toBe(0);
    expect(file_exists('patches/drupal/views/change.diff'))->toBeTrue();
});

it('uses the URL filename stem as the default description', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'patch content'),
    ]));

    $writtenDescription = null;
    $this->io->method('write');
    // Return empty string to accept the default (filename stem)
    $this->io->method('ask')->willReturnCallback(function ($prompt, $default) use (&$writtenDescription) {
        $writtenDescription = $default;

        return $default;
    });

    $this->tester->execute([
        'source' => 'https://example.com/files/my-great-fix.patch',
        '--package' => 'drupal/core',
    ]);

    expect($writtenDescription)->toBe('my-great-fix');
});

it('asks for the package name interactively when --package is not provided', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'patch content'),
    ]));

    $this->io->method('write');
    // First ask: package name; second ask: description
    $this->io->method('ask')->willReturnOnConsecutiveCalls('drupal/core', 'Interactive description');

    $result = $this->tester->execute(['source' => 'https://example.com/files/fix.patch']);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->toHaveKey('drupal/core');
});

it('errors in non-interactive mode when --package is not provided', function () {
    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('--package is required'));

    $result = $this->tester->execute(
        ['source' => 'https://example.com/files/fix.patch'],
        ['interactive' => false]
    );

    expect($result)->toBe(1);
});

it('errors when the URL does not end in .patch or .diff', function () {
    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('.patch or .diff'));

    $result = $this->tester->execute([
        'source' => 'https://github.com/owner/repo/pull/123',
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(1);
});

// ---------------------------------------------------------------------------
// Local file path tests
// ---------------------------------------------------------------------------

it('copies a local .patch file to the patches directory', function () {
    $localFile = $this->tempDir.'/my-fix.patch';
    file_put_contents($localFile, 'patch content');

    $this->io->method('write');
    $this->io->method('ask')->willReturn('My description');

    $result = $this->tester->execute([
        'source' => $localFile,
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(0);
    expect(file_exists('patches/drupal/core/my-fix.patch'))->toBeTrue();

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches'])->toHaveKey('drupal/core');
});

it('copies a local .diff file to the patches directory', function () {
    $localFile = $this->tempDir.'/my-change.diff';
    file_put_contents($localFile, 'diff content');

    $this->io->method('write');
    $this->io->method('ask')->willReturn('My description');

    $result = $this->tester->execute([
        'source' => $localFile,
        '--package' => 'drupal/views',
    ]);

    expect($result)->toBe(0);
    expect(file_exists('patches/drupal/views/my-change.diff'))->toBeTrue();
});

it('registers a local file already in the patches directory without copying', function () {
    mkdir('patches/drupal/core', 0777, true);
    $existingFile = 'patches/drupal/core/already-there.patch';
    file_put_contents($existingFile, 'patch content');

    $this->io->method('write');
    $this->io->method('ask')->willReturn('Existing description');

    $result = $this->tester->execute([
        'source' => $existingFile,
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    $patches = $json['extra']['patches']['drupal/core'];
    expect($patches[0]['url'])->toBe($existingFile);
});

it('errors when a local file does not exist', function () {
    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('File not found'));

    $result = $this->tester->execute([
        'source' => '/nonexistent/path/fix.patch',
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(1);
});

it('errors when a local path does not end in .patch or .diff', function () {
    $localFile = $this->tempDir.'/readme.txt';
    file_put_contents($localFile, 'not a patch');

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('.patch or .diff'));

    $result = $this->tester->execute([
        'source' => $localFile,
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(1);
});

it('uses --description option and skips the interactive prompt', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new Response(200, [], 'patch content'),
    ]));

    $this->io->method('write');
    $this->io->expects($this->never())->method('ask');

    $result = $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
        '--description' => 'My custom description',
    ]);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches']['drupal/core'][0]['description'])->toBe('My custom description');
});

it('uses --description option for a local file without prompting', function () {
    $localFile = $this->tempDir.'/my-fix.patch';
    file_put_contents($localFile, 'patch content');

    $this->io->method('write');
    $this->io->expects($this->never())->method('ask');

    $result = $this->tester->execute([
        'source' => $localFile,
        '--package' => 'drupal/core',
        '--description' => 'Local patch description',
    ]);

    expect($result)->toBe(0);

    $json = json_decode(file_get_contents($this->tempComposerJson), true);
    expect($json['extra']['patches']['drupal/core'][0]['description'])->toBe('Local patch description');
});

it('errors when the download fails', function () {
    $this->sf->setGuzzleClient(makeGuzzleClient([
        new RequestException('Connection refused', new Request('GET', 'test')),
    ]));

    $this->io->method('write');
    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('Failed to download patch'));

    $result = $this->tester->execute([
        'source' => 'https://example.com/files/fix.patch',
        '--package' => 'drupal/core',
    ]);

    expect($result)->toBe(1);
});
