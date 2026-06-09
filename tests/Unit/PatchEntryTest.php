<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bounteous\Darn\Patch\PatchEntry;

it('creates entry from legacy string format', function () {
    $entry = PatchEntry::fromComposerData('My description', 'patches/file.patch');

    expect($entry->description)->toBe('My description');
    expect($entry->url)->toBe('patches/file.patch');
    expect($entry->depth)->toBeNull();
    expect($entry->issueTrackerUrl)->toBeNull();
});

it('creates entry from modern array format', function () {
    $entry = PatchEntry::fromComposerData(0, [
        'description' => 'Modern patch',
        'url' => 'patches/modern.patch',
    ]);

    expect($entry->description)->toBe('Modern patch');
    expect($entry->url)->toBe('patches/modern.patch');
    expect($entry->depth)->toBeNull();
    expect($entry->issueTrackerUrl)->toBeNull();
});

it('creates entry from modern array format with optional fields', function () {
    $entry = PatchEntry::fromComposerData(0, [
        'description' => 'Deep patch',
        'url' => 'patches/deep.patch',
        'depth' => 1,
        'extra' => ['issue-tracker-url' => 'https://example.com/issue/42'],
    ]);

    expect($entry->description)->toBe('Deep patch');
    expect($entry->url)->toBe('patches/deep.patch');
    expect($entry->depth)->toBe(1);
    expect($entry->issueTrackerUrl)->toBe('https://example.com/issue/42');
});

it('serialises minimal entry to array', function () {
    $entry = new PatchEntry('My patch', 'patches/file.patch');

    expect($entry->toArray())->toBe([
        'description' => 'My patch',
        'url' => 'patches/file.patch',
    ]);
});

it('serialises full entry to array including optional fields', function () {
    $entry = new PatchEntry('My patch', 'patches/file.patch', 1, 'https://example.com/42');

    expect($entry->toArray())->toBe([
        'description' => 'My patch',
        'url' => 'patches/file.patch',
        'depth' => 1,
        'extra' => ['issue-tracker-url' => 'https://example.com/42'],
    ]);
});

it('round-trips legacy format through fromComposerData and toArray', function () {
    $entry = PatchEntry::fromComposerData('Legacy description', 'patches/legacy.patch');

    expect($entry->toArray())->toBe([
        'description' => 'Legacy description',
        'url' => 'patches/legacy.patch',
    ]);
});

it('omits depth from array when null', function () {
    $entry = new PatchEntry('desc', 'url', null, 'https://tracker.example');

    $array = $entry->toArray();
    expect($array)->not->toHaveKey('depth');
    expect($array['extra']['issue-tracker-url'])->toBe('https://tracker.example');
});

it('omits issueTrackerUrl from array when null', function () {
    $entry = new PatchEntry('desc', 'url', 2, null);

    $array = $entry->toArray();
    expect($array['depth'])->toBe(2);
    expect($array)->not->toHaveKey('extra');
});

it('creates entry from modern array format with ticket', function () {
    $entry = PatchEntry::fromComposerData(0, [
        'description' => 'Patched fix',
        'url' => 'patches/fix.patch',
        'extra' => ['ticket' => 'JIRA-123'],
    ]);

    expect($entry->ticket)->toBe('JIRA-123');
    expect($entry->issueTrackerUrl)->toBeNull();
});

it('serialises ticket into extra alongside issue-tracker-url', function () {
    $entry = new PatchEntry('Fix', 'patches/fix.patch', null, 'https://drupal.org/42', 'JIRA-123');

    expect($entry->toArray())->toBe([
        'description' => 'Fix',
        'url' => 'patches/fix.patch',
        'extra' => [
            'issue-tracker-url' => 'https://drupal.org/42',
            'ticket' => 'JIRA-123',
        ],
    ]);
});

it('serialises ticket alone when issue-tracker-url is null', function () {
    $entry = new PatchEntry('Fix', 'patches/fix.patch', null, null, 'PROJ-99');

    expect($entry->toArray())->toBe([
        'description' => 'Fix',
        'url' => 'patches/fix.patch',
        'extra' => ['ticket' => 'PROJ-99'],
    ]);
});

it('omits ticket from array when null', function () {
    $entry = new PatchEntry('Fix', 'patches/fix.patch', null, null, null);

    expect($entry->toArray())->not->toHaveKey('extra');
});

it('ticket is null when absent from composer data', function () {
    $entry = PatchEntry::fromComposerData(0, ['description' => 'Fix', 'url' => 'patches/fix.patch']);

    expect($entry->ticket)->toBeNull();
});
