<?php

declare(strict_types=1);

namespace Bounteous\Darn\Patch;

/**
 * Contract for reading and writing the patches registry in composer.json.
 */
interface PatchManagerInterface
{
    /**
     * Reads the composer.json file and returns its contents as an array.
     *
     * @return array<mixed>
     *
     * @throws \RuntimeException
     */
    public function readComposerJson(): array;

    /**
     * Writes the given data to composer.json.
     *
     * @param  array<mixed>  $json
     */
    public function writeComposerJson(array $json): bool;

    /**
     * Adds or updates a patch entry in composer.json.
     *
     * @param  string  $filepath  Relative path to the patch file.
     * @param  string  $packageName  Composer package name (e.g. drupal/core).
     * @param  string  $description  Human-readable patch description.
     * @param  string|null  $issueUrl  Optional upstream issue URL for tracking.
     * @param  int|null  $depth  Optional git-apply --depth value.
     * @param  string|null  $ticket  Optional internal ticket reference (e.g. JIRA-123).
     */
    public function addPatch(string $filepath, string $packageName, string $description, ?string $issueUrl, ?int $depth, ?string $ticket = null): bool;

    /**
     * Removes a patch entry by package name and exact description.
     *
     * Returns false if no matching entry was found.
     */
    public function removePatch(string $packageName, string $description): bool;

    /**
     * Atomically removes an existing entry and adds a replacement in a single
     * read-modify-write cycle, avoiding the double file I/O of separate
     * removePatch() + addPatch() calls.
     *
     * Returns false if the old entry was not found or the write fails.
     */
    public function replacePatch(string $oldPackageName, string $oldDescription, string $newFilepath, string $newPackageName, string $newDescription, ?string $issueUrl, ?int $depth, ?string $ticket = null): bool;
}
