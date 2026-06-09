<?php

declare(strict_types=1);

namespace Bounteous\Darn\Patch;

use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * Reads and writes the patches registry in composer.json.
 *
 * All patch entries are stored under extra.patches in the modern array format
 * (each entry is an associative array with at minimum 'description' and 'url'
 * keys). Legacy string-keyed entries are normalised to this format on first
 * read via PatchEntry::fromComposerData().
 *
 * Packages are kept sorted alphabetically; within each package, entries are
 * sorted case-insensitively by description to produce deterministic diffs.
 */
class PatchManager implements PatchManagerInterface
{
    /**
     * @param  IOInterface  $io  Used to report write errors to the user.
     */
    public function __construct(protected IOInterface $io)
    {
    }

    /**
     * Reads the composer.json file.
     *
     * @return array<mixed>
     *
     * @throws \RuntimeException
     */
    public function readComposerJson(): array
    {
        $composerJsonPath = Factory::getComposerFile();
        $jsonFile = new JsonFile($composerJsonPath);

        if (! $jsonFile->exists()) {
            throw new \RuntimeException("composer.json not found at $composerJsonPath");
        }

        try {
            return $jsonFile->read();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to parse composer.json: '.$e->getMessage());
        }
    }

    /**
     * Writes data to composer.json.
     *
     * @param  array<mixed>  $json
     */
    public function writeComposerJson(array $json): bool
    {
        $composerJsonPath = Factory::getComposerFile();
        $jsonFile = new JsonFile($composerJsonPath);

        try {
            $jsonFile->write($json);
        } catch (\Exception $e) {
            $this->io->writeError("<error>Failed to write to $composerJsonPath: ".$e->getMessage().'</error>');

            return false;
        }

        return true;
    }

    /**
     * Adds or updates a patch entry in composer.json.
     */
    public function addPatch(string $filepath, string $packageName, string $description, ?string $issueUrl, ?int $depth, ?string $ticket = null): bool
    {
        try {
            $json = $this->readComposerJson();
        } catch (\Exception $e) {
            $this->io->writeError('<error>'.$e->getMessage().'</error>');

            return false;
        }

        $json['extra']['patches'][$packageName] ??= [];
        $packagePatches = $json['extra']['patches'][$packageName];

        $newEntry = new PatchEntry($description, $filepath, $depth, $issueUrl, $ticket);

        $newPackagePatches = [];
        $updated = false;

        foreach ($packagePatches as $key => $value) {
            $existing = PatchEntry::fromComposerData($key, $value);
            if ($existing->description === $description) {
                $newPackagePatches[] = $newEntry->toArray();
                $updated = true;
            } else {
                $newPackagePatches[] = $existing->toArray();
            }
        }

        if (! $updated) {
            $newPackagePatches[] = $newEntry->toArray();
        }

        $json['extra']['patches'][$packageName] = $newPackagePatches;

        $this->sortPatches($json['extra']['patches']);

        return $this->writeComposerJson($json);
    }

    public function replacePatch(string $oldPackageName, string $oldDescription, string $newFilepath, string $newPackageName, string $newDescription, ?string $issueUrl, ?int $depth, ?string $ticket = null): bool
    {
        try {
            $json = $this->readComposerJson();
        } catch (\Exception $e) {
            $this->io->writeError('<error>'.$e->getMessage().'</error>');

            return false;
        }

        if (! isset($json['extra']['patches'][$oldPackageName])) {
            return false;
        }

        $found = false;
        $remaining = [];

        foreach ($json['extra']['patches'][$oldPackageName] as $key => $value) {
            $entry = PatchEntry::fromComposerData($key, $value);
            if ($entry->description === $oldDescription) {
                $found = true;
            } else {
                $remaining[] = $entry->toArray();
            }
        }

        if (! $found) {
            return false;
        }

        if ($remaining === []) {
            unset($json['extra']['patches'][$oldPackageName]);
        } else {
            $json['extra']['patches'][$oldPackageName] = $remaining;
        }

        $json['extra']['patches'][$newPackageName] ??= [];
        $json['extra']['patches'][$newPackageName][] = (new PatchEntry($newDescription, $newFilepath, $depth, $issueUrl, $ticket))->toArray();

        $this->sortPatches($json['extra']['patches']);

        return $this->writeComposerJson($json);
    }

    /**
     * @param  array<mixed>  $patches
     */
    private function sortPatches(array &$patches): void
    {
        ksort($patches);
        $patches = array_map(static function (array $list): array {
            usort($list, fn ($a, $b) => strcasecmp($a['description'] ?? '', $b['description'] ?? ''));

            return $list;
        }, $patches);
    }

    /**
     * Removes a patch entry by package name and exact description.
     *
     * Returns false if the entry was not found.
     */
    public function removePatch(string $packageName, string $description): bool
    {
        try {
            $json = $this->readComposerJson();
        } catch (\Exception $e) {
            $this->io->writeError('<error>'.$e->getMessage().'</error>');

            return false;
        }

        if (! isset($json['extra']['patches'][$packageName])) {
            return false;
        }

        $found = false;
        $newPatches = [];

        foreach ($json['extra']['patches'][$packageName] as $key => $value) {
            $entry = PatchEntry::fromComposerData($key, $value);
            if ($entry->description === $description) {
                $found = true;
            } else {
                $newPatches[] = $entry->toArray();
            }
        }

        if (! $found) {
            return false;
        }

        if ($newPatches === []) {
            unset($json['extra']['patches'][$packageName]);
        } else {
            $json['extra']['patches'][$packageName] = $newPatches;
        }

        return $this->writeComposerJson($json);
    }
}
