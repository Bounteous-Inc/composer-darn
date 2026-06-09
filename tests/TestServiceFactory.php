<?php

declare(strict_types=1);

namespace Tests;

use Bounteous\Darn\Patch\PatchManagerInterface;
use Bounteous\Darn\Patch\PatchValidator;
use Bounteous\Darn\Service\ServiceFactory;
use GuzzleHttp\Client;

/**
 * Extends ServiceFactory to allow injecting test doubles for the Guzzle client
 * and PatchValidator without modifying production command code.
 *
 * Typical test setup
 * ------------------
 * 1. Create the factory (requires a Composer IOInterface):
 *
 *    $sf = new TestServiceFactory(new NullIO());
 *
 * 2. Inject whichever doubles the test needs:
 *
 *    $sf->setGuzzleClient(makeGuzzleClient([new Response(200, [], 'body')]));
 *    $sf->setPatchValidator($this->createMock(PatchValidator::class));
 *    $sf->setPatchManager($this->createMock(PatchManagerInterface::class));
 *
 * 3. Hand the factory to the command under test before executing:
 *
 *    $command = new MyCommand();
 *    $command->setServiceFactory($sf);
 *    $command->run($input, $output);
 *
 * Any factory method that has not been overridden delegates to the real
 * ServiceFactory parent, so you only need to stub what the test actually cares
 * about. See tests/Integration/ for concrete examples.
 */
class TestServiceFactory extends ServiceFactory
{
    private ?Client $injectedGuzzleClient = null;

    private ?PatchValidator $injectedPatchValidator = null;

    private ?PatchManagerInterface $injectedPatchManager = null;

    /**
     * Injects a pre-built Guzzle client (e.g. one with a MockHandler) to be
     * used instead of the default client created by the factory.
     */
    public function setGuzzleClient(Client $client): void
    {
        $this->injectedGuzzleClient = $client;
    }

    /**
     * Returns the injected client if one has been set, otherwise delegates to
     * the parent factory which creates a real client from the stored options.
     */
    public function getGuzzleClient(): Client
    {
        return $this->injectedGuzzleClient ?? parent::getGuzzleClient();
    }

    /**
     * Replaces the client options AND rebuilds the injected client (if any) so
     * that options set during initialize() — such as Authorization headers —
     * propagate to the mock handler used in tests.
     *
     * @param  array<string, mixed>  $options
     */
    public function setGuzzleClientOptions(array $options): void
    {
        parent::setGuzzleClientOptions($options);

        if ($this->injectedGuzzleClient !== null) {
            $handler = $this->injectedGuzzleClient->getConfig('handler');
            $this->injectedGuzzleClient = new Client(array_merge($options, ['handler' => $handler]));
        }
    }

    /**
     * Injects a PatchManager stub so tests can control composer.json I/O
     * without touching real files (or to verify read/write calls on a mock).
     */
    public function setPatchManager(PatchManagerInterface $manager): void
    {
        $this->injectedPatchManager = $manager;
    }

    public function getPatchManager(): PatchManagerInterface
    {
        return $this->injectedPatchManager ?? parent::getPatchManager();
    }

    /**
     * Injects a PatchValidator stub so tests can control validation outcomes
     * without running real git-apply checks.
     */
    public function setPatchValidator(PatchValidator $validator): void
    {
        $this->injectedPatchValidator = $validator;
    }

    public function getPatchValidator(): PatchValidator
    {
        return $this->injectedPatchValidator ?? parent::getPatchValidator();
    }
}
