<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Top-level entry point that routes a source to the appropriate darn:* command.
 *
 * Detection order:
 *   1. drupal.org host + issue path       → darn:drupal.org (issue ID extracted)
 *   2. github.com host + /pull/ path      → darn:github
 *   3. Path ends in .patch/.diff          → darn:patch (URL or local file)
 *
 * All patch-authoring options (--apply, --depth, --package) are accepted here
 * and forwarded to the delegate command as appropriate.
 */
class DispatchCommand extends DarnCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('darn')
            ->setDescription('Add a patch from a Drupal.org issue, GitHub PR, direct URL, or local file.')
            ->addArgument('source', InputArgument::REQUIRED, 'Drupal.org issue URL, GitHub PR URL, direct .patch/.diff URL, or local .patch/.diff path.')
            ->addOption('apply', 'a', InputOption::VALUE_NONE, 'Automatically apply the new patch after updating composer.json.')
            ->addOption('depth', 'p', InputOption::VALUE_REQUIRED, 'The patch depth to use (e.g. 0 or 1).', null)
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Composer package name (required for direct patch files when auto-detection is unavailable).')
            ->addOption('ticket', null, InputOption::VALUE_REQUIRED, 'Internal ticket or issue reference to associate with this patch (e.g. JIRA-123).', null)
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Patch description written to composer.json (skips interactive prompt).', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $source = $input->getArgument('source');

        $parsed = parse_url($source);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        // Drupal.org issue URL — extract the numeric issue ID.
        if (str_contains($host, 'drupal.org') && preg_match('#/(?:node|issues)/(\d+)#', $path, $m) === 1) {
            return $this->delegate('darn:drupal.org', [
                'issue_id'       => $m[1],
                '--dir'          => $input->getOption('dir'),
                '--apply'        => $input->getOption('apply'),
                '--depth'        => $input->getOption('depth'),
                '--ticket'       => $input->getOption('ticket'),
                '--description'  => $input->getOption('description'),
            ], $input, $output);
        }

        // GitHub Pull Request URL.
        if ($host === 'github.com' && str_contains($path, '/pull/')) {
            return $this->delegate('darn:github', [
                'url'            => $source,
                'package'        => $input->getOption('package'),
                '--dir'          => $input->getOption('dir'),
                '--apply'        => $input->getOption('apply'),
                '--depth'        => $input->getOption('depth'),
                '--ticket'       => $input->getOption('ticket'),
                '--description'  => $input->getOption('description'),
            ], $input, $output);
        }

        // Direct .patch/.diff — URL or local file path.
        if (str_ends_with($path, '.patch') || str_ends_with($path, '.diff')) {
            return $this->delegate('darn:patch', [
                'source'         => $source,
                '--package'      => $input->getOption('package'),
                '--dir'          => $input->getOption('dir'),
                '--apply'        => $input->getOption('apply'),
                '--depth'        => $input->getOption('depth'),
                '--ticket'       => $input->getOption('ticket'),
                '--description'  => $input->getOption('description'),
            ], $input, $output);
        }

        $io->writeError("<error>Unrecognised source: $source</error>");
        $io->writeError('Supported: Drupal.org issue URL, GitHub PR URL, or a direct .patch/.diff URL or file path.');

        return 1;
    }

    /**
     * Finds a named command and runs it with the given parameters.
     *
     * Null and false values are stripped so unset options are not forwarded
     * (equivalent to the option not having been passed at all).
     *
     * @param  array<string, mixed>  $parameters
     */
    private function delegate(string $commandName, array $parameters, InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find($commandName);
        // array_filter with no callback treats '0' as falsy, dropping --depth 0.
        // Use an explicit callback that only removes null/false (unset options).
        $commandInput = new ArrayInput(array_filter($parameters, fn ($v) => $v !== null && $v !== false));
        $commandInput->setInteractive($input->isInteractive());

        return $command->run($commandInput, $output);
    }
}
