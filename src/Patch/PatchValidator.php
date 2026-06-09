<?php

declare(strict_types=1);

namespace Bounteous\Darn\Patch;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;

class PatchValidator
{
    /**
     * Validates a downloaded patch file before it is registered in composer.json.
     *
     * Runs two checks in sequence:
     *   1. Format     — the file must be non-empty and start with a recognisable
     *                   diff header (unified, git, Subversion, or raw hunk).
     *   2. Applicability — `git apply --check` is run against the installed
     *                   package directory as a true dry-run (no files modified).
     *                   Skipped with a warning when the package is not yet
     *                   installed or its path cannot be resolved.
     *
     * @param  string  $filepath  Path to the downloaded patch file.
     * @param  string  $packageName  Composer package name (e.g. drupal/core).
     * @param  Composer  $composer  Active Composer instance used to locate the
     *                              package's install directory.
     * @param  int|null  $depth  Strip-count for `git apply -p<n>`. Null uses
     *                           -p1, the standard for unified diffs from git.
     * @param  IOInterface  $io  Used to emit warnings and errors.
     */
    public function validate(
        string $filepath,
        string $packageName,
        Composer $composer,
        ?int $depth,
        IOInterface $io
    ): bool {
        if (! $this->validateFormat($filepath, $io)) {
            return false;
        }

        return $this->validateApplicability($filepath, $packageName, $composer, $depth, $io);
    }

    /**
     * Checks that the file is non-empty and contains a recognisable diff header.
     *
     * Only the first 4 KB is read to avoid loading large files into memory.
     * The pattern matches unified diff (diff /---), Subversion (Index:), and
     * raw hunk (@@) headers to cover the range of formats produced by
     * Drupal.org and GitHub.
     */
    private function validateFormat(string $filepath, IOInterface $io): bool
    {
        if (! is_file($filepath) || filesize($filepath) === 0) {
            $io->writeError('<error>Downloaded patch file is empty.</error>');

            return false;
        }

        $head = file_get_contents($filepath, false, null, 0, 4096);
        if ($head === false || preg_match('/^(diff |--- |Index: |@@)/m', $head) !== 1) {
            $io->writeError('<error>Downloaded file does not appear to be a valid patch.</error>');

            return false;
        }

        return true;
    }

    /**
     * Runs a dry-run `git apply --check` against the installed package directory.
     *
     * The check is skipped (returning true with a warning) when the package is
     * not yet installed, its installer cannot resolve an install path, or the
     * resolved path does not exist on disk — all of which are normal during an
     * initial `composer install` before dependencies are present.
     */
    private function validateApplicability(
        string $filepath,
        string $packageName,
        Composer $composer,
        ?int $depth,
        IOInterface $io
    ): bool {
        $repo = $composer->getRepositoryManager()->getLocalRepository();
        $package = $repo->findPackage($packageName, '*');

        if ($package === null) {
            $io->write("<warning>Package $packageName is not installed; skipping applicability check.</warning>");

            return true;
        }

        try {
            $path = $composer->getInstallationManager()
                ->getInstaller($package->getType())
                ->getInstallPath($package);
        } catch (\Exception) {
            $io->write("<warning>Could not determine install path for $packageName; skipping applicability check.</warning>");

            return true;
        }

        if ($path === null || ! is_dir($path)) {
            $io->write("<warning>Install path for $packageName not found; skipping applicability check.</warning>");

            return true;
        }

        try {
            $result = $this->runDryRun($filepath, $path, $depth, $io);
        } catch (\Exception $e) {
            $io->writeError('<error>Patch validation failed: '.$e->getMessage().'</error>');

            return false;
        }

        if (! $result) {
            $io->writeError("<error>Patch does not apply cleanly to $packageName.</error>");
        }

        return $result;
    }

    /**
     * Executes `git apply --check` against the package directory.
     *
     * Protected (rather than private) so tests can subclass and override this
     * method with a stub that avoids real filesystem operations.
     *
     * @param  string  $filepath  Path to the local patch file.
     * @param  string  $path  Absolute path to the installed package directory.
     * @param  int|null  $depth  Strip-prefix depth (-p<n>); defaults to -p1.
     * @param  IOInterface  $io  Passed through to ProcessExecutor for output.
     */
    protected function runDryRun(string $filepath, string $path, ?int $depth, IOInterface $io): bool
    {
        $executor = new ProcessExecutor($io);
        $depthArg = $depth !== null ? '-p'.$depth : '-p1';
        $realFilepath = realpath($filepath);
        $resolvedFilepath = $realFilepath !== false ? $realFilepath : $filepath;

        return $executor->execute(sprintf(
            'git -C %s apply --check %s %s',
            escapeshellarg($path),
            $depthArg,
            escapeshellarg($resolvedFilepath)
        )) === 0;
    }
}
