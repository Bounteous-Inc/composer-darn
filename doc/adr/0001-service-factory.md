# 1. ServiceFactory as lazy-loading DI container

Date: 2026-04-10

## Status

Accepted

## Context

Composer plugins run inside the host project's Composer process. Every class the plugin loads adds to memory and initialization time for every `composer` invocation, including ones that have nothing to do with patch management. A plugin that eagerly wires up an HTTP client, API clients, and a patch validator on every `composer install` imposes that cost even when the user is just running `composer require some/package`.

The alternatives considered were:

- **Symfony DependencyInjection component** — the canonical PHP DI solution, but pulling `symfony/dependency-injection` into a Composer plugin means it lands in every consuming project's vendor tree. It also requires compiled container generation or reflection-based autowiring, both of which add complexity disproportionate to the problem.
- **Constructor injection throughout** — straightforward but forces every command to accept every dependency it might transitively need, producing long constructor signatures and eager instantiation of services that may never be used.
- **No abstraction (direct instantiation)** — simple, but makes the code untestable without real HTTP and real filesystem state.

## Decision

Services are created on first use via a single `ServiceFactory` class (`src/Service/ServiceFactory.php`). Each command holds a `private ?ServiceFactory` property, initialised lazily via `DarnCommand::getServiceFactory()`. The factory creates and caches each service instance the first time it is requested.

For tests, `TestServiceFactory` (`tests/TestServiceFactory.php`) subclasses `ServiceFactory` and overrides `getGuzzleClient()` and `getPatchValidator()` to return test doubles. Commands receive the test factory via `DarnCommand::setServiceFactory()` before execution.

## Consequences

- HTTP clients, API clients, and the patch validator are not instantiated unless the command actually needs them. Most Composer invocations pay no initialization cost beyond class autoloading.
- The dependency on `symfony/dependency-injection` (and its transitive dependencies) is avoided entirely.
- Test injection is straightforward: subclass `ServiceFactory`, override the method for the service you want to stub, pass the subclass to `setServiceFactory()`. No mocking framework or interface proliferation required.
- The factory is the single source of truth for how each service is wired. Adding a new service means adding one method to `ServiceFactory` (and an override to `TestServiceFactory` if test doubles are needed).
- The trade-off accepted is that `ServiceFactory` is a service locator, not true dependency injection. Commands pull from the factory rather than declaring explicit dependencies. This is an intentional choice for the Composer plugin context; it would not be appropriate in a larger application.
