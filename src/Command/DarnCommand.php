<?php

declare(strict_types=1);

namespace Bounteous\Darn\Command;

use Bounteous\Darn\Service\ServiceFactory;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for all darn:* Composer commands.
 *
 * Provides the shared --dir option, stores input/output for subclass use, and
 * exposes a ServiceFactory that centralises dependency construction.
 */
abstract class DarnCommand extends BaseCommand
{
    /** Stored in initialize() so subclass execute() methods can access input without re-injecting it. */
    protected InputInterface $input;

    /** Stored in initialize() so subclass execute() methods can access output without re-injecting it. */
    protected OutputInterface $output;

    private ?ServiceFactory $serviceFactory = null;

    protected function configure(): void
    {
        $this->addOption(
            'dir',
            null,
            InputOption::VALUE_REQUIRED,
            "Override the patches directory (default: 'patches').",
            null
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Injects an alternative ServiceFactory — primarily used in tests to swap
     * out individual services (Guzzle client, PatchValidator, etc.) without
     * touching the command code.
     */
    public function setServiceFactory(ServiceFactory $serviceFactory): void
    {
        $this->serviceFactory = $serviceFactory;
    }

    /**
     * Returns the active ServiceFactory, creating a default instance on first use.
     */
    protected function getServiceFactory(): ServiceFactory
    {
        return $this->serviceFactory ??= new ServiceFactory($this->getIO());
    }

    /**
     * Resolves the directory where patch files are stored.
     *
     * Resolution order:
     *   1. The --dir CLI option (or $override argument) if provided.
     *   2. The extra.composer-darn.patches-dir key in composer.json.
     *   3. The default 'patches' directory at the project root.
     */
    protected function getPatchesDirectory(?string $override = null): string
    {
        if ($override !== null) {
            return $override;
        }

        $extra = $this->requireComposer()->getPackage()->getExtra();
        if (isset($extra['composer-darn']['patches-dir'])) {
            return $extra['composer-darn']['patches-dir'];
        }

        return 'patches';
    }

    /**
     * Configures the Guzzle client with the GitHub User-Agent and, when
     * GITHUB_TOKEN is set, an Authorization header to avoid rate limits.
     */
    protected function applyGitHubClientOptions(): void
    {
        $headers = ['User-Agent' => 'Composer-Darn'];

        $token = getenv('GITHUB_TOKEN');
        if ($token !== false) {
            $headers['Authorization'] = 'token '.$token;
        }

        $this->getServiceFactory()->setGuzzleClientOptions([
            'headers' => $headers,
            'timeout' => 10,
        ]);
    }

    /**
     * Scans the patches directory for .patch files.
     *
     * @param  string|null  $packageName  Optional package name to limit the scan.
     * @return array<string> List of file paths relative to the project root.
     */
    protected function getPatchesFromDisk(?string $packageName = null): array
    {
        $patchesDir = $this->getPatchesDirectory($this->input->getOption('dir'));
        if ($packageName !== null) {
            $patchesDir .= '/'.$packageName;
        }
        if (! is_dir($patchesDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($patchesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['patch', 'diff'], true)) {
                // Normalize Windows path separators.
                $path = str_replace('\\', '/', $file->getPathname());
                // Strip a leading './' that some systems prepend when the patches
                // directory is passed as a relative path like './patches'.
                if (str_starts_with($path, './')) {
                    $path = substr($path, 2);
                }
                $files[] = $path;
            }
        }

        return $files;
    }
}
