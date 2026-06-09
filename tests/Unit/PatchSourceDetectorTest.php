<?php

declare(strict_types=1);

use Bounteous\Darn\Patch\PatchSourceDetector;

describe('PatchSourceDetector::detect()', function () {
    it('returns LOCAL for null', function () {
        expect(PatchSourceDetector::detect(null))->toBe(PatchSourceDetector::TYPE_LOCAL);
    });

    it('returns LOCAL for empty string', function () {
        expect(PatchSourceDetector::detect(''))->toBe(PatchSourceDetector::TYPE_LOCAL);
    });

    it('returns LOCAL for a relative file path', function () {
        expect(PatchSourceDetector::detect('patches/drupal/core/fix.patch'))->toBe(PatchSourceDetector::TYPE_LOCAL);
    });

    it('returns LOCAL for an absolute file path', function () {
        expect(PatchSourceDetector::detect('/var/www/patches/fix.patch'))->toBe(PatchSourceDetector::TYPE_LOCAL);
    });

    it('returns DRUPAL for a drupal.org file attachment URL', function () {
        expect(PatchSourceDetector::detect('https://www.drupal.org/files/issues/2024-01-15/3456789-23-fix.patch'))
            ->toBe(PatchSourceDetector::TYPE_DRUPAL);
    });

    it('returns GITHUB for a github.com pull request URL', function () {
        expect(PatchSourceDetector::detect('https://github.com/owner/repo/pull/123'))
            ->toBe(PatchSourceDetector::TYPE_GITHUB);
    });

    it('returns GITHUB for a github.com pull request URL with .patch extension', function () {
        expect(PatchSourceDetector::detect('https://github.com/owner/repo/pull/123.patch'))
            ->toBe(PatchSourceDetector::TYPE_GITHUB);
    });

    it('returns GITHUB for a github.com pull request URL with .diff extension', function () {
        expect(PatchSourceDetector::detect('https://github.com/owner/repo/pull/123.diff'))
            ->toBe(PatchSourceDetector::TYPE_GITHUB);
    });

    it('returns GITHUB for a patch-diff.githubusercontent.com URL', function () {
        expect(PatchSourceDetector::detect('https://patch-diff.githubusercontent.com/raw/owner/repo/pull/123.patch'))
            ->toBe(PatchSourceDetector::TYPE_GITHUB);
    });

    it('returns UNKNOWN for a gitlab.com merge request URL', function () {
        expect(PatchSourceDetector::detect('https://gitlab.com/some/project/-/merge_requests/42.patch'))
            ->toBe(PatchSourceDetector::TYPE_UNKNOWN);
    });

    it('returns UNKNOWN for an unrecognized remote URL', function () {
        expect(PatchSourceDetector::detect('https://example.com/some.patch'))
            ->toBe(PatchSourceDetector::TYPE_UNKNOWN);
    });
});

describe('PatchSourceDetector::extractDrupalIssueId()', function () {
    it('extracts issue ID from a standard drupal.org file URL', function () {
        $url = 'https://www.drupal.org/files/issues/2024-01-15/3456789-23-fix.patch';
        expect(PatchSourceDetector::extractDrupalIssueId($url))->toBe(3456789);
    });

    it('extracts issue ID without a comment suffix', function () {
        $url = 'https://www.drupal.org/files/issues/2020-06-01/1234567.patch';
        expect(PatchSourceDetector::extractDrupalIssueId($url))->toBe(1234567);
    });

    it('returns null for a GitHub URL', function () {
        expect(PatchSourceDetector::extractDrupalIssueId('https://github.com/owner/repo/pull/1.patch'))
            ->toBeNull();
    });

    it('returns null for a local path', function () {
        expect(PatchSourceDetector::extractDrupalIssueId('patches/fix.patch'))->toBeNull();
    });
});

describe('PatchSourceDetector::extractGitHubPrInfo()', function () {
    it('extracts info from a bare github.com pull URL', function () {
        $info = PatchSourceDetector::extractGitHubPrInfo('https://github.com/acme/widget/pull/99');
        expect($info)->toBe([
            'owner' => 'acme',
            'repo' => 'widget',
            'number' => 99,
            'prUrl' => 'https://github.com/acme/widget/pull/99',
        ]);
    });

    it('extracts info from a .patch-suffixed PR URL', function () {
        $info = PatchSourceDetector::extractGitHubPrInfo('https://github.com/acme/widget/pull/99.patch');
        expect($info)->not->toBeNull();
        expect($info['number'])->toBe(99);
        expect($info['prUrl'])->toBe('https://github.com/acme/widget/pull/99');
    });

    it('extracts info from a patch-diff.githubusercontent.com URL', function () {
        $info = PatchSourceDetector::extractGitHubPrInfo(
            'https://patch-diff.githubusercontent.com/raw/acme/widget/pull/99.patch'
        );
        expect($info)->toBe([
            'owner' => 'acme',
            'repo' => 'widget',
            'number' => 99,
            'prUrl' => 'https://github.com/acme/widget/pull/99',
        ]);
    });

    it('returns null for a drupal.org URL', function () {
        expect(PatchSourceDetector::extractGitHubPrInfo(
            'https://www.drupal.org/files/issues/2024-01-15/12345-1.patch'
        ))->toBeNull();
    });

    it('returns null for a local path', function () {
        expect(PatchSourceDetector::extractGitHubPrInfo('patches/fix.patch'))->toBeNull();
    });
});
