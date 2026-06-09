<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Patch\PatchSourceDetector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Downloads a patch from a GitHub Pull Request and registers it in composer.json.
 *
 * Accepts either a PR page URL (github.com/…/pull/N) or a direct .diff/.patch URL.
 * When no package name is provided, the command attempts to auto-detect it by
 * fetching the target repository's own composer.json via the GitHub raw API.
 *
 * Set the GITHUB_TOKEN environment variable to authenticate and avoid rate limits.
 */
class GithubCommand extends BasePatchCommand
{
    /**
     * Configures the Guzzle client options (User-Agent, optional Authorization
     * header) on the service factory so they are applied before the first HTTP
     * request is made in execute().
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->applyGitHubClientOptions();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn:github')
            ->setDescription('Download and register a patch from a GitHub Pull Request or direct .diff/.patch URL.')
            ->addArgument('url', InputArgument::REQUIRED, 'GitHub Pull Request URL or direct .diff/.patch URL.')
            ->addArgument('package', InputArgument::OPTIONAL, 'Composer package name (auto-detected from the repo\'s composer.json if omitted).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $url = $input->getArgument('url');
        $packageName = $input->getArgument('package');

        $prMeta = PatchSourceDetector::extractGitHubPrInfo($url);
        if ($prMeta !== null) {
            $patchUrl = $prMeta['prUrl'].'.diff';
            $filename = "{$prMeta['owner']}-{$prMeta['repo']}-pr-{$prMeta['number']}.patch";
        } elseif (substr($url, -5) === '.diff' || substr($url, -6) === '.patch') {
            $patchUrl = $url;
            $filename = basename(explode('?', $url)[0]);
        } else {
            $io->writeError('<error>The URL must be a GitHub Pull Request or end in .diff/.patch</error>');

            return 1;
        }

        if ($packageName === null || $packageName === '') {
            $detectedPackage = $prMeta !== null
                ? $this->getServiceFactory()->getGitHubClient()->detectPackageName($prMeta['owner'], $prMeta['repo'])
                : null;
            if ($input->isInteractive()) {
                $packageName = $io->ask('Package name to apply patch to'.($detectedPackage !== null ? " [<comment>$detectedPackage</comment>]" : '').': ', $detectedPackage);
            } else {
                $packageName = $detectedPackage;
            }
        }

        if ($packageName === null || $packageName === '') {
            $io->writeError('<error>Package name is required.</error>');

            return 1;
        }

        $io->write("<info>Downloading patch from $patchUrl</info>");

        $patchesDir = $this->getPatchesDirectory($input->getOption('dir'));

        $targetDir = $patchesDir.'/'.$packageName;
        if (! is_dir($targetDir)) {
            if (! mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
                $io->writeError("<error>Failed to create directory $targetDir</error>");

                return 1;
            }
        }

        $targetPath = $targetDir.'/'.$filename;

        try {
            $this->getServiceFactory()->getGitHubClient()->downloadPatch($patchUrl, $targetPath);
        } catch (\Exception $e) {
            $io->writeError("<error>Failed to download patch from $patchUrl: ".$e->getMessage().'</error>');

            return 1;
        }

        $io->write("<info>Saved patch to $targetPath</info>");

        $descriptionOption = $input->getOption('description');
        if ($descriptionOption !== null) {
            $description = $descriptionOption;
        } else {
            // For PR URLs, fetch the PR title from the GitHub API so the stored
            // description is self-documenting. Falls back to "Patch from $url" on failure.
            $defaultDescription = "Patch from $url";
            if ($prMeta !== null) {
                $pr = $this->getServiceFactory()->getGitHubClient()->fetchPullRequest(
                    $prMeta['owner'],
                    $prMeta['repo'],
                    $prMeta['number']
                );
                if ($pr !== null && $pr['title'] !== '') {
                    $defaultDescription = "PR #{$prMeta['number']}: {$pr['title']} ({$prMeta['owner']}/{$prMeta['repo']})";
                }
            }

            if ($input->isInteractive()) {
                $trimmed = trim(
                    $io->ask("Description [<comment>$defaultDescription</comment>]: ", $defaultDescription) ?? $defaultDescription
                );
                $description = $trimmed !== '' ? $trimmed : $defaultDescription;
            } else {
                $description = $defaultDescription;
            }
        }

        $issueUrl = explode('?', $url)[0];

        $ticketOption = $input->getOption('ticket');
        $ticket = ($ticketOption !== null && $ticketOption !== false) ? (string) $ticketOption : null;

        return $this->registerPatch($targetPath, $packageName, $description, $issueUrl, $this->getDepthOption($input), $io, $ticket);
    }
}
