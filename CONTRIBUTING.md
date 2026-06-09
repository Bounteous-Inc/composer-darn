# Contributing

Thank you for your interest in contributing to Composer Darn!

## Prerequisites

- PHP 8.2+
- [Composer](https://getcomposer.org/)
- Git

## Setup

```bash
git clone https://github.com/Bounteous-Inc/composer-darn.git
cd composer-darn
composer install
```

GrumPHP installs git hooks automatically during `composer install`. Each commit runs linting, static analysis scoped to staged files, and the fast `Unit` test suite. The full suite (Unit + Integration + Acceptance) runs in CI.

## Running checks

```bash
composer lint          # Check code style with Laravel Pint (PSR-12)
composer fix           # Auto-fix code style violations
composer analyze       # PHPStan static analysis (level 8)
composer test          # Full test suite (Unit + Integration + Acceptance)
composer check         # All of the above
```

## Commit messages

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Examples:

```
feat(drupal.org): support GitLab MR diffs
fix(github): handle missing PR title gracefully
docs: update darn:verify CI example
test: cover query-string stripping in GithubCommand
chore: bump phpstan to 2.x
```

The GrumPHP hook will reject commits that do not follow this format.

## Submitting a pull request

1. Create a branch from `main`.
2. Make your changes with tests.
3. Ensure `composer check` passes.
4. Open a pull request against `main` with a clear description of the change and why it is needed.

## Releasing

Releases are automated. Maintainers do not run any local release commands.

### How it works

The [Release workflow](.github/workflows/release.yml) runs automatically after every CI-green merge to `main`:

- **Any regular merge** — the workflow computes the next version using [git-cliff](https://git-cliff.org/) (based on Conventional Commits since the last tag), updates `CHANGELOG.md`, then opens a `release/X.Y.Z` pull request. If that PR already exists it is force-pushed with refreshed content.
- **Merging a `release/X.Y.Z` PR** — the workflow detects the release commit and pushes the `vX.Y.Z` tag. No CHANGELOG changes are made.

Version bumping follows Conventional Commits: `feat` bumps minor, `fix` bumps patch, a breaking change (`!` or `BREAKING CHANGE` footer) bumps major.

### Maintainer checklist

When a release PR appears:

1. Review `CHANGELOG.md` — confirm the version and entries look correct.
2. Merge the PR. The workflow tags automatically; no manual steps needed.

### Prerequisites

None — the workflow uses the repository's default `GITHUB_TOKEN`, which already has sufficient permissions to push branches, tags, and open pull requests within this repository.
