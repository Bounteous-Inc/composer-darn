<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)->in('Integration', 'Acceptance', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Builds a Guzzle Client backed by a MockHandler for use in unit tests.
 *
 * @param  array<\GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|\Throwable>  $responses
 * @param  array<mixed>  $history  Passed by reference; each recorded transaction is appended.
 */
function makeGuzzleClient(array $responses, array &$history = []): \GuzzleHttp\Client
{
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
    $stack->push(\GuzzleHttp\Middleware::history($history));

    return new \GuzzleHttp\Client(['handler' => $stack]);
}

/**
 * Builds a TestServiceFactory pre-loaded with a passthrough PatchValidator.
 */
function makeTestSf(\Composer\IO\IOInterface $io): \Tests\TestServiceFactory
{
    $sf = new \Tests\TestServiceFactory($io);
    $sf->setPatchValidator(new class () extends \Bounteous\Darn\Patch\PatchValidator {
        public function validate(string $filepath, string $packageName, \Composer\Composer $composer, ?int $depth, \Composer\IO\IOInterface $io): bool
        {
            return true;
        }
    });

    return $sf;
}
