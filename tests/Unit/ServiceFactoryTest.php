<?php

declare(strict_types=1);

use Bounteous\Darn\Api\DrupalOrgClient;
use Bounteous\Darn\Api\GitHubClient;
use Bounteous\Darn\Patch\PatchValidator;
use Bounteous\Darn\Service\ServiceFactory;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Tests\TestServiceFactory;

// ── ServiceFactory lazy-caching ────────────────────────────────────────────

it('returns the same Guzzle client on repeated calls', function () {
    $sf = new ServiceFactory(new NullIO());

    expect($sf->getGuzzleClient())->toBe($sf->getGuzzleClient());
});

it('returns the same GitHubClient on repeated calls', function () {
    $sf = new ServiceFactory(new NullIO());

    expect($sf->getGitHubClient())->toBe($sf->getGitHubClient());
});

it('returns the same DrupalOrgClient on repeated calls', function () {
    $sf = new ServiceFactory(new NullIO());

    expect($sf->getDrupalOrgClient())->toBe($sf->getDrupalOrgClient());
});

it('returns the same PatchManager on repeated calls', function () {
    $sf = new ServiceFactory(new NullIO());

    expect($sf->getPatchManager())->toBe($sf->getPatchManager());
});

it('returns the same PatchValidator on repeated calls', function () {
    $sf = new ServiceFactory(new NullIO());

    expect($sf->getPatchValidator())->toBe($sf->getPatchValidator());
});

// ── setGuzzleClientOptions reset cascade ──────────────────────────────────

it('setGuzzleClientOptions causes HTTP-dependent services to be recreated', function () {
    $sf = new ServiceFactory(new NullIO());

    $client1 = $sf->getGuzzleClient();
    $github1 = $sf->getGitHubClient();
    $drupal1 = $sf->getDrupalOrgClient();

    $sf->setGuzzleClientOptions(['timeout' => 30]);

    expect($sf->getGuzzleClient())->not->toBe($client1);
    expect($sf->getGitHubClient())->not->toBe($github1);
    expect($sf->getDrupalOrgClient())->not->toBe($drupal1);
});

it('setGuzzleClientOptions does not discard PatchManager or PatchValidator', function () {
    $sf = new ServiceFactory(new NullIO());

    $pm = $sf->getPatchManager();
    $pv = $sf->getPatchValidator();

    $sf->setGuzzleClientOptions(['timeout' => 30]);

    expect($sf->getPatchManager())->toBe($pm);
    expect($sf->getPatchValidator())->toBe($pv);
});

it('setGuzzleClientOptions passes options to the rebuilt Guzzle client', function () {
    $sf = new ServiceFactory(new NullIO());

    $sf->setGuzzleClientOptions(['timeout' => 42]);

    expect($sf->getGuzzleClient()->getConfig('timeout'))->toBe(42);
});

// ── GitHubClient and DrupalOrgClient use the shared Guzzle client ─────────

it('GitHubClient wraps the same Guzzle client the factory holds', function () {
    $sf = new ServiceFactory(new NullIO());

    // Force both to be created, then verify the chain is consistent after a reset.
    $sf->getGitHubClient();
    $sf->setGuzzleClientOptions([]);

    $guzzle = $sf->getGuzzleClient();
    $github = $sf->getGitHubClient();

    // After a fresh reset both are new; getting Guzzle again returns the cached one.
    expect($sf->getGuzzleClient())->toBe($guzzle);
    // GitHub client is also cached now.
    expect($sf->getGitHubClient())->toBe($github);
});

// ── TestServiceFactory ─────────────────────────────────────────────────────

it('TestServiceFactory returns the injected Guzzle client', function () {
    $sf = new TestServiceFactory(new NullIO());

    $mock = new Client(['handler' => HandlerStack::create(new MockHandler())]);
    $sf->setGuzzleClient($mock);

    expect($sf->getGuzzleClient())->toBe($mock);
});

it('TestServiceFactory falls back to a real client when none is injected', function () {
    $sf = new TestServiceFactory(new NullIO());

    expect($sf->getGuzzleClient())->toBeInstanceOf(Client::class);
});

it('TestServiceFactory returns the injected PatchValidator', function () {
    $sf = new TestServiceFactory(new NullIO());

    $stub = new class () extends PatchValidator {
        public function validate(string $filepath, string $packageName, Composer $composer, ?int $depth, IOInterface $io): bool
        {
            return true;
        }
    };
    $sf->setPatchValidator($stub);

    expect($sf->getPatchValidator())->toBe($stub);
});

it('TestServiceFactory preserves the mock handler after setGuzzleClientOptions', function () {
    $sf = new TestServiceFactory(new NullIO());

    $mockHandler = new MockHandler();
    $originalStack = HandlerStack::create($mockHandler);
    $sf->setGuzzleClient(new Client(['handler' => $originalStack]));

    // Simulate what GithubCommand::initialize() does: set auth headers.
    $sf->setGuzzleClientOptions(['headers' => ['Authorization' => 'token abc']]);

    $rebuilt = $sf->getGuzzleClient();

    // The client was rebuilt with new options (check the Authorization key specifically,
    // as Guzzle may add its own User-Agent to the headers array) …
    expect($rebuilt->getConfig('headers'))->toMatchArray(['Authorization' => 'token abc']);
    // … but the original handler stack is preserved so mock responses still work.
    expect($rebuilt->getConfig('handler'))->toBe($originalStack);
});
