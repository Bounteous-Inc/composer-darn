![Darn](assets/darn-wordmark.svg)

[![CI](https://github.com/Bounteous-Inc/composer-darn/actions/workflows/ci.yml/badge.svg)](https://github.com/Bounteous-Inc/composer-darn/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/bounteous/composer-darn)](https://packagist.org/packages/bounteous/composer-darn)
[![PHP Version](https://img.shields.io/packagist/php-v/bounteous/composer-darn)](composer.json)
[![License](https://img.shields.io/packagist/l/bounteous/composer-darn)](LICENSE)

A Composer plugin for fetching, registering, and managing patches in your project. Supports **Drupal.org** issues, **GitHub** pull requests, and direct `.patch`/`.diff` URLs or local files. Fully compatible with [cweagans/composer-patches](https://github.com/cweagans/composer-patches).

## Requirements

* PHP >= 8.2
* Composer >= 2.0

## Installation

```bash
composer require --dev bounteous/composer-darn
```

To actually apply patches during `composer install` / `composer update`, you also need:

```bash
composer require cweagans/composer-patches
```

`composer darn` will offer to install it for you when needed if it is missing.

> **Installing into a project with existing patches?** If your `composer.json` already has entries under `extra.patches` when `bounteous/composer-darn` is first installed, it will prompt you to run `darn:fix` automatically. This normalises your existing entries to the standard format with self-documenting descriptions, `issue-tracker-url` fields, and locally-stored patch files.

## Quick Start

The `darn` command is a smart dispatcher — give it any supported URL or file path and it routes to the right sub-command automatically:

```bash
# From a Drupal.org issue
composer darn https://www.drupal.org/project/drupal/issues/3151000

# From a GitHub pull request
composer darn https://github.com/owner/repo/pull/123

# From a direct patch URL
composer darn https://example.com/my-fix.patch --package=vendor/package

# From a local file
composer darn ./my-fix.patch --package=vendor/package
```

All commands support `--apply` to immediately apply the patch with `cweagans/composer-patches` after registering the patch.

## Commands

### `darn` — Smart Dispatcher

Detects the source type and delegates to the appropriate sub-command.

```bash
composer darn <source> [options]
```

| Argument / Option | Description |
| --- | --- |
| `source` | Drupal.org issue URL, GitHub PR URL, direct `.patch`/`.diff` URL, or local file path |
| `--package` | Package name — required for direct URLs/files when auto-detection is unavailable |
| `--dir` | Override patches directory (default: `patches`) |
| `--apply`, `-a` | Apply the patch via `cweagans/composer-patches` after registering |
| `--depth`, `-p` | Strip-prefix depth passed to `git apply -p<n>` |
| `--ticket` | Internal ticket reference (e.g. `JIRA-123`) stored in `composer.json` |
| `--description` | Patch description — skips the interactive prompt |

---

### `darn:drupal.org` — Drupal.org Issues

Download and register a patch from a Drupal.org issue.

```bash
composer darn:drupal.org [<issue_id>] [options]
```

Without an `issue_id`, the command prompts for one interactively.

**What it does:**

1. Queries the Drupal.org REST API for the issue.
2. Resolves the Composer package name from the project machine name.
3. Lists all available patches: GitLab Merge Request diffs and `.patch` file attachments (newest first).
4. Prompts you to select a patch.
5. Downloads the patch to `patches/<package>/`.
6. Cleans up previously downloaded patches for the same issue if found.
7. Registers the patch in `composer.json` under `extra.patches`.

**Filename format:**

* Merge Request: `{issueId}-mr-{iid}-{sha8}.patch`
* File attachment: `{issueId}-{commentIndex}-{filename}`

**Example:**

```bash
composer darn:drupal.org 3151000 --apply
```

---

### `darn:github` — GitHub Pull Requests

Download and register a patch from a GitHub pull request.

```bash
composer darn:github <url> [<package>] [options]
```

For PR URLs (`github.com/owner/repo/pull/N`), the package name is auto-detected from the repository's `composer.json`. Provide `<package>` explicitly if detection fails.

**What it does:**

1. Auto-detects the package name and generates a self-documenting filename (`{owner}-{repo}-pr-{number}.patch`).
2. Downloads the patch to `patches/<package>/`.
3. Fetches the PR title from the GitHub API and uses it as the default description (`PR #N: {title} (owner/repo)`).
4. Registers the patch in `composer.json`.

**Authentication:**

Set `GITHUB_TOKEN` to avoid rate limits and access private repositories:

```bash
export GITHUB_TOKEN=ghp_yourtoken
composer darn:github https://github.com/owner/repo/pull/123
```

**Example:**

```bash
composer darn:github https://github.com/drupal/drupal/pull/456 --apply
```

---

### `darn:patch` — Direct URL or Local File

Register a patch from any direct URL or local `.patch`/`.diff` file.

```bash
composer darn:patch <source> --package=<vendor/name> [options]
```

`--package` is required because there is no API to auto-detect it.

**What it does:**

1. For URLs: downloads the file to `patches/<package>/`.
2. For local files: copies the file to the patches directory (unless it is already there).
3. Prompts for a description (defaults to the filename without extension).
4. Registers the patch in `composer.json`.

**Example:**

```bash
composer darn:patch https://example.com/fix.patch --package=drupal/core --description="Fix broken layout"
```

---

### `darn:fix` — Normalize Existing Patch Entries

Re-fetch metadata for all remote patches in `composer.json` and rewrite their entries to the standard format: self-documenting description, `issue-tracker-url`, and a locally-stored patch file.

```bash
composer darn:fix [--dry-run] [--dir=patches]
```

**What it does:**

1. Scans every entry under `extra.patches`.
2. For each **Drupal.org** URL: fetches the issue and file metadata, generates the canonical description (`Issue #N: {title} ({file})`), downloads the patch to `patches/<package>/`, and updates the entry.
3. For each **GitHub PR** URL: fetches the PR title, downloads the `.diff`, and updates the entry.
4. **Skips** local files, GitLab URLs, and any unrecognized remote URLs.

| Option | Description |
| --- | --- |
| `--dry-run` | Show what would change without downloading or writing anything |
| `--dir` | Override the patches directory (default: `patches`) |

**Example:**

```bash
# Preview changes first
composer darn:fix --dry-run

# Apply normalization
composer darn:fix
```

---

### `darn:list` — List Registered Patches

List all patches in `composer.json`, grouped by package.

```bash
composer darn:list [<package>] [--dir=patches]
```

Each entry shows whether the patch file exists on disk:

```text
drupal/core
  [✓] Issue #3151000: Fix render pipeline (patches/drupal-core/3151000-1-fix.patch)
  [✗] Issue #3200000: Missing layout (patches/drupal-core/3200000-1-layout.patch)

2 patch(es) registered for 1 package(s). 1 missing.
```

Always exits `0` — use `darn:verify` for CI checks.

---

### `darn:verify` — Verify Patches Exist

Verify that every patch file referenced in `composer.json` exists on disk.

```bash
composer darn:verify [--prune] [--dir=patches]
```

Also detects:

* Duplicate patch URLs registered under different entries
* Patches directory listed in `.gitignore` (patches should be committed)

**Exit codes:** `0` = all present, `1` = any missing or malformed.

**With `--prune`:** delegates to `darn:prune` for interactive cleanup.

**CI/CD example:**

```yaml
# GitHub Actions
- name: Verify patches
  run: composer darn:verify
```

---

### `darn:remove` — Remove a Patch Entry

Remove one or more patches from `composer.json`, optionally deleting the files from disk.

```bash
composer darn:remove [<package>] [<description>] [--delete] [--dir=patches]
```

**Modes:**

* **Both arguments supplied:** removes the specified entry non-interactively (safe for scripts).
* **Package only:** shows a numbered list of patches for that package; prompts for selection.
* **No arguments:** first selects a package interactively, then shows the patch list.

Use `--delete` to also remove the patch file(s) from disk without an extra prompt.

**Example:**

```bash
# Non-interactive removal (e.g. in a script)
composer darn:remove drupal/core "Issue #3151000: Fix render pipeline" --delete
```

---

### `darn:prune` — Remove Orphaned Files and Stale Entries

Detect and clean up two categories of staleness:

```bash
composer darn:prune [<package>] [--clean-config] [--dir=patches]
```

| Category | Description | Cleaned up by |
| --- | --- | --- |
| **Orphaned files** | `.patch`/`.diff` files on disk with no matching `composer.json` entry | Always offered (prompts to delete) |
| **Missing entries** | `composer.json` entries whose file no longer exists | Requires `--clean-config` |

Scope to a single package with the optional `package` argument.

---

## Configuration

### Patches Directory

Override the default `patches/` directory globally via `composer.json`:

```json
{
  "extra": {
    "composer-darn": {
      "patches-dir": "custom/patches"
    }
  }
}
```

Or per-command with `--dir`:

```bash
composer darn:list --dir=custom/patches
```

### GitHub Token

Set `GITHUB_TOKEN` to authenticate with the GitHub API:

```bash
export GITHUB_TOKEN=ghp_yourtoken
```

This avoids rate limits (60 req/hr unauthenticated vs. 5,000 req/hr authenticated) and is required for private repositories. In GitHub Actions, `GITHUB_TOKEN` is provided automatically:

```yaml
- name: Register patch
  run: composer darn https://github.com/owner/repo/pull/123
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, development workflow, commit conventions, and the release process.

## License

MIT — see [LICENSE](LICENSE).
