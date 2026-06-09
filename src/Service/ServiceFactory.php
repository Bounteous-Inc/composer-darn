<?php

declare(strict_types=1);

namespace Bounteous\Darn\Service;

use Bounteous\Darn\Api\DrupalOrgClient;
use Bounteous\Darn\Api\GitHubClient;
use Bounteous\Darn\Patch\PatchManager;
use Bounteous\Darn\Patch\PatchManagerInterface;
use Bounteous\Darn\Patch\PatchValidator;
use Composer\IO\IOInterface;
use GuzzleHttp\Client;

/**
 * Centralized lazy-loading container for all command services.
 *
 * Provides a single place where service instances are created, allowing
 * subclasses (e.g. TestServiceFactory) to override individual getters and
 * inject test doubles without touching command code.
 */
class ServiceFactory
{
    private ?Client $guzzleClient = null;

    private ?GitHubClient $gitHubClient = null;

    private ?DrupalOrgClient $drupalOrgClient = null;

    private ?DrupalIssueService $drupalIssueService = null;

    private ?PatchManagerInterface $patchManager = null;

    private ?ComposerPatchesInstaller $composerPatchesInstaller = null;

    private ?PatchValidator $patchValidator = null;

    private ?PatchApplicationService $patchApplicationService = null;

    /** @var array<string, mixed> */
    private array $guzzleClientOptions = [];

    public function __construct(private readonly IOInterface $io)
    {
    }

    /**
     * Replaces the Guzzle client options and discards any previously cached
     * HTTP clients so they are re-created with the new options on next use.
     *
     * @param  array<string, mixed>  $options
     */
    public function setGuzzleClientOptions(array $options): void
    {
        $this->guzzleClientOptions = $options;
        $this->guzzleClient = null;
        $this->gitHubClient = null;
        $this->drupalOrgClient = null;
        $this->drupalIssueService = null;
    }

    public function getGuzzleClient(): Client
    {
        return $this->guzzleClient ??= new Client($this->guzzleClientOptions);
    }

    public function getGitHubClient(): GitHubClient
    {
        return $this->gitHubClient ??= new GitHubClient($this->getGuzzleClient());
    }

    public function getDrupalOrgClient(): DrupalOrgClient
    {
        return $this->drupalOrgClient ??= new DrupalOrgClient($this->getGuzzleClient());
    }

    public function getDrupalIssueService(): DrupalIssueService
    {
        return $this->drupalIssueService ??= new DrupalIssueService($this->getDrupalOrgClient(), $this->io);
    }

    public function getPatchManager(): PatchManagerInterface
    {
        return $this->patchManager ??= new PatchManager($this->io);
    }

    public function getComposerPatchesInstaller(): ComposerPatchesInstaller
    {
        return $this->composerPatchesInstaller ??= new ComposerPatchesInstaller();
    }

    public function getPatchValidator(): PatchValidator
    {
        return $this->patchValidator ??= new PatchValidator();
    }

    public function getPatchApplicationService(): PatchApplicationService
    {
        return $this->patchApplicationService ??= new PatchApplicationService();
    }
}
