<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Patch\PatchValidator;
use Composer\Composer;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;

/**
 * Testable subclass that controls the dry-run result without spawning a real git process.
 */
class TestablePatchValidator extends PatchValidator
{
    public bool $dryRunResult = true;

    public ?\Exception $dryRunException = null;

    protected function runDryRun(string $filepath, string $path, ?int $depth, IOInterface $io): bool
    {
        if ($this->dryRunException !== null) {
            throw $this->dryRunException;
        }

        return $this->dryRunResult;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeComposer(
    ?PackageInterface $package = null,
    ?string $installPath = null
): Composer {
    $localRepo = test()->createMock(InstalledRepositoryInterface::class);
    $localRepo->method('findPackage')->willReturn($package);

    $repoManager = test()->createMock(RepositoryManager::class);
    $repoManager->method('getLocalRepository')->willReturn($localRepo);

    $composer = test()->createMock(Composer::class);
    $composer->method('getRepositoryManager')->willReturn($repoManager);

    if ($package !== null && $installPath !== null) {
        $installer = test()->createMock(InstallerInterface::class);
        $installer->method('getInstallPath')->willReturn($installPath);

        $installationManager = test()->createMock(InstallationManager::class);
        $installationManager->method('getInstaller')->willReturn($installer);

        $composer->method('getInstallationManager')->willReturn($installationManager);
    }

    return $composer;
}

function makeIo(): IOInterface
{
    $io = test()->createMock(IOInterface::class);
    $io->method('write');
    $io->method('writeError');

    return $io;
}

function makePatchFile(string $content = ''): string
{
    $path = sys_get_temp_dir().'/test_'.uniqid().'.patch';
    file_put_contents($path, $content);

    return $path;
}

// ── Format validation ─────────────────────────────────────────────────────────

it('fails validation when file is empty', function () {
    $path = makePatchFile('');
    $validator = new TestablePatchValidator();

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('writeError')
        ->with('<error>Downloaded patch file is empty.</error>');

    $result = $validator->validate($path, 'drupal/core', makeComposer(), null, $io);

    expect($result)->toBeFalse();
    unlink($path);
});

it('fails validation when file contains no diff markers', function () {
    $path = makePatchFile('<html><body>404 Not Found</body></html>');
    $validator = new TestablePatchValidator();

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('writeError')
        ->with('<error>Downloaded file does not appear to be a valid patch.</error>');

    $result = $validator->validate($path, 'drupal/core', makeComposer(), null, $io);

    expect($result)->toBeFalse();
    unlink($path);
});

it('passes format validation when file has unified diff header', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1,3 +1,3 @@\n context\n-old\n+new\n";
    $path = makePatchFile($content);

    // makeComposer() with no args → findPackage returns null → applicability is skipped
    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', makeComposer(), null, makeIo());

    expect($result)->toBeTrue();
    unlink($path);
});

it('passes format validation when file starts with diff --git header', function () {
    $content = "diff --git a/file.php b/file.php\n--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', makeComposer(), null, makeIo());

    expect($result)->toBeTrue();
    unlink($path);
});

// ── Applicability: skip cases ─────────────────────────────────────────────────

it('skips applicability check when package is not in the local repository', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('write')
        ->with($this->stringContains('not installed'));

    // makeComposer(null) → findPackage returns null
    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', makeComposer(null), null, $io);

    expect($result)->toBeTrue();
    unlink($path);
});

it('skips applicability check when install path is not a directory', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $package = $this->createMock(PackageInterface::class);
    $package->method('getType')->willReturn('library');

    $composer = makeComposer($package, '/nonexistent/path/that/does/not/exist');

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('write')
        ->with($this->stringContains('not found'));

    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', $composer, null, $io);

    expect($result)->toBeTrue();
    unlink($path);
});

// ── Applicability: dry-run outcomes ───────────────────────────────────────────

it('passes validation when dry-run succeeds', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $tempDir = sys_get_temp_dir().'/pkg_'.uniqid();
    mkdir($tempDir);

    $package = $this->createMock(PackageInterface::class);
    $package->method('getType')->willReturn('library');

    $validator = new TestablePatchValidator();
    $validator->dryRunResult = true;

    $result = $validator->validate($path, 'drupal/core', makeComposer($package, $tempDir), null, makeIo());

    expect($result)->toBeTrue();

    unlink($path);
    rmdir($tempDir);
});

it('fails validation when dry-run fails', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $tempDir = sys_get_temp_dir().'/pkg_'.uniqid();
    mkdir($tempDir);

    $package = $this->createMock(PackageInterface::class);
    $package->method('getType')->willReturn('library');

    $validator = new TestablePatchValidator();
    $validator->dryRunResult = false;

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('writeError')
        ->with($this->stringContains('does not apply cleanly'));

    $result = $validator->validate($path, 'drupal/core', makeComposer($package, $tempDir), null, $io);

    expect($result)->toBeFalse();

    unlink($path);
    rmdir($tempDir);
});

it('fails validation when dry-run throws an exception', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $tempDir = sys_get_temp_dir().'/pkg_'.uniqid();
    mkdir($tempDir);

    $package = $this->createMock(PackageInterface::class);
    $package->method('getType')->willReturn('library');

    $validator = new TestablePatchValidator();
    $validator->dryRunException = new \Exception('git: command not found');

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('writeError')
        ->with($this->stringContains('Patch validation failed'));

    $result = $validator->validate($path, 'drupal/core', makeComposer($package, $tempDir), null, $io);

    expect($result)->toBeFalse();

    unlink($path);
    rmdir($tempDir);
});

// ── Additional format validation ──────────────────────────────────────────────

it('passes format validation when file has a Subversion Index: header', function () {
    $content = "Index: file.php\n--- file.php\t(revision 1)\n+++ file.php\t(working copy)\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    // makeComposer() with no args → findPackage returns null → applicability is skipped
    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', makeComposer(), null, makeIo());

    expect($result)->toBeTrue();
    unlink($path);
});

it('passes format validation when file starts with a raw @@ hunk header', function () {
    $content = "@@ -1,3 +1,3 @@\n context\n-old\n+new\n";
    $path = makePatchFile($content);

    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', makeComposer(), null, makeIo());

    expect($result)->toBeTrue();
    unlink($path);
});

it('skips applicability check when getInstaller throws', function () {
    $content = "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n-old\n+new\n";
    $path = makePatchFile($content);

    $package = $this->createMock(PackageInterface::class);
    $package->method('getType')->willReturn('library');

    $localRepo = $this->createMock(InstalledRepositoryInterface::class);
    $localRepo->method('findPackage')->willReturn($package);

    $repoManager = $this->createMock(RepositoryManager::class);
    $repoManager->method('getLocalRepository')->willReturn($localRepo);

    $installationManager = $this->createMock(InstallationManager::class);
    $installationManager->method('getInstaller')
        ->willThrowException(new \InvalidArgumentException('No installer for type library'));

    $composer = $this->createMock(Composer::class);
    $composer->method('getRepositoryManager')->willReturn($repoManager);
    $composer->method('getInstallationManager')->willReturn($installationManager);

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->once())
        ->method('write')
        ->with($this->stringContains('Could not determine install path'));

    $result = (new TestablePatchValidator())->validate($path, 'drupal/core', $composer, null, $io);

    expect($result)->toBeTrue();
    unlink($path);
});
