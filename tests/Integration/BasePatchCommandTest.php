<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\BasePatchCommand;
use Bounteous\Darn\Patch\PatchValidator;
use Bounteous\Darn\Service\ServiceFactory;
use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

/**
 * PatchValidator that always returns true, so existing BasePatchCommand
 * integration tests focus on the composer.json update logic without needing
 * real patch files on disk.
 */
class PassthroughPatchValidator extends PatchValidator
{
    public function validate(
        string $filepath,
        string $packageName,
        Composer $composer,
        ?int $depth,
        IOInterface $io
    ): bool {
        return true;
    }
}

/**
 * PatchValidator whose result is controlled by a property, for testing the
 * validation failure path in BasePatchCommand::registerPatch().
 */
class ControllablePatchValidator extends PatchValidator
{
    public bool $shouldPass = true;

    public function validate(
        string $filepath,
        string $packageName,
        Composer $composer,
        ?int $depth,
        IOInterface $io
    ): bool {
        if (! $this->shouldPass) {
            $io->writeError("<error>Patch does not apply cleanly to $packageName.</error>");
        }

        return $this->shouldPass;
    }
}

// Concrete class for testing abstract BasePatchCommand
class TestBasePatchCommand extends BasePatchCommand
{
    public $installTriggered = false;

    public $mockTrigger = false;

    public $composerMock;

    private ?PatchValidator $validatorOverride = null;

    public bool $isInstalled = true;

    public bool $installCalled = false;

    private ?TestServiceFactory $testFactory = null;

    public function setValidatorOverride(?PatchValidator $validator): void
    {
        $this->validatorOverride = $validator;
        if ($this->testFactory !== null) {
            $this->testFactory->setPatchValidator($validator ?? new PassthroughPatchValidator());
        }
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('test:patch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function testRegisterPatch($filepath, $packageName, $description, ?string $issueUrl, ?int $depth, IOInterface $io)
    {
        return $this->registerPatch($filepath, $packageName, $description, $issueUrl, $depth, $io);
    }

    public function setApplyPatches(bool $apply)
    {
        $this->applyPatches = $apply;
    }

    // This method is used when we WANT to test the real trigger logic with a mock executor
    public function testTriggerComposerInstall(IOInterface $io)
    {
        return $this->triggerComposerInstall($io);
    }

    protected function triggerComposerInstall(IOInterface $io): void
    {
        // Use a property to decide if we run real logic or just flag it
        if ($this->mockTrigger) {
            $this->installTriggered = true;

            return;
        }

        parent::triggerComposerInstall($io);
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        return $this->composerMock ?? parent::getComposer($required, $disablePlugins, $disableScripts);
    }

    public function requireComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): Composer
    {
        return $this->composerMock ?? parent::requireComposer($disablePlugins, $disableScripts);
    }

    public function setInput(InputInterface $input)
    {
        $this->input = $input;
    }

    protected function isPatchPackageInstalled(): bool
    {
        return $this->isInstalled;
    }

    protected function installPatchPackage(IOInterface $io): void
    {
        $this->installCalled = true;
    }

    protected function getServiceFactory(): ServiceFactory
    {
        if ($this->testFactory === null) {
            $this->testFactory = new TestServiceFactory($this->getIO());
            $this->testFactory->setPatchValidator($this->validatorOverride ?? new PassthroughPatchValidator());
        }

        return $this->testFactory;
    }

    public function testRunNormalize(IOInterface $io): void
    {
        $this->runNormalizeIfAvailable($io);
    }
}

beforeEach(function () {
    $this->tempComposerJson = sys_get_temp_dir().'/composer_'.uniqid().'.json';
    file_put_contents($this->tempComposerJson, '{}');

    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->io->method('writeError');
    $this->io->method('write');

    $this->output = $this->createMock(OutputInterface::class);

    $this->composer = $this->createMock(Composer::class);
    $this->application = $this->createMock(Application::class);
    $this->application->method('has')->willReturn(false);

    $this->command = new TestBasePatchCommand();
    $this->command->mockTrigger = true;
    $this->command->composerMock = $this->composer;
    $this->command->setOutput($this->output);
    $this->command->setApplication($this->application);

    $this->tester = new CommandTester($this->command);
});

afterEach(function () {
    if (file_exists($this->tempComposerJson)) {
        unlink($this->tempComposerJson);
    }
    putenv('COMPOSER'); // Unset
});

it('updates composer.json correctly', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $patchName = 'Test Patch';
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";

    $result = $this->command->testRegisterPatch($filepath, $packageName, $description, null, null, $this->io);

    expect($result)->toBe(0);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $patches = $json['extra']['patches'][$packageName];
    expect($patches)->toHaveCount(1);
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['description'])->toBe($description);
});

it('updates composer.json correctly with expanded format', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $patchName = 'Test Patch';
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";
    $issueUrl = "https://example.com/issues/$issueId";

    $result = $this->command->testRegisterPatch($filepath, $packageName, $description, $issueUrl, null, $this->io);

    expect($result)->toBe(0);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $patches = $json['extra']['patches'][$packageName];
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['description'])->toBe($description);
    expect($patches[0]['extra']['issue-tracker-url'])->toBe($issueUrl);
});

it('updates composer.json correctly with depth', function () {
    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $patchName = 'Test Patch';
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";
    $depth = 2;

    $result = $this->command->testRegisterPatch($filepath, $packageName, $description, null, $depth, $this->io);

    expect($result)->toBe(0);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $patches = $json['extra']['patches'][$packageName];
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['depth'])->toBe($depth);
    expect($patches[0]['description'])->toBe($description);
    expect($patches[0])->not->toHaveKey('extra');
});

it('updates an empty composer.json', function () {
    // Start with a completely empty JSON object
    file_put_contents($this->tempComposerJson, '{}');

    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $description = 'A new patch';
    $issueUrl = 'http://example.com/issue';

    $result = $this->command->testRegisterPatch($filepath, $packageName, $description, $issueUrl, null, $this->io);

    expect($result)->toBe(0);

    $content = file_get_contents($this->tempComposerJson);
    if ($content === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }

    $json = json_decode($content, true);

    $patches = $json['extra']['patches'][$packageName];
    expect($patches[0]['url'])->toBe($filepath);
    expect($patches[0]['description'])->toBe($description);
    expect($patches[0]['extra']['issue-tracker-url'])->toBe($issueUrl);
});

it('sorts patches alphabetically by package name and description', function () {
    // Pre-populate with a package that should come after 'a-package' alphabetically.
    // Also include a package with unsorted patches.
    $initialJson = [
        'extra' => [
            'patches' => [
                'z-package' => ['some patch' => 'path/to/patch'],
                'a-package' => [
                    'Z Patch' => 'path/to/z',
                    'A Patch' => 'path/to/a',
                ],
            ],
        ],
    ];
    file_put_contents($this->tempComposerJson, json_encode($initialJson));

    $filepath = 'patches/core.patch';
    $packageName = 'a-package';
    $patchName = 'M Patch'; // Should fit in the middle
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";

    $this->command->testRegisterPatch($filepath, $packageName, $description, null, null, $this->io);

    $jsonContent = file_get_contents($this->tempComposerJson);
    if ($jsonContent === false) {
        throw new \RuntimeException("Failed to read temporary composer.json at: {$this->tempComposerJson}");
    }
    $json = json_decode($jsonContent, true);

    // Check package sorting
    $packageKeys = array_keys($json['extra']['patches']);
    expect($packageKeys)->toBe(['a-package', 'z-package']);

    // Check patch sorting within a-package
    // Note: The new patch "Issue 12345: M Patch" should be inserted and sorted.
    // Expected order: "A Patch", "Issue 12345: M Patch", "Z Patch"
    $descriptions = array_column($json['extra']['patches']['a-package'], 'description');
    expect($descriptions)->toBe([
        'A Patch',
        $description,
        'Z Patch',
    ]);
});

it('triggers composer install when --apply is used', function () {
    $this->command->setApplyPatches(true);

    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $patchName = 'Test Patch';
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";

    $this->command->testRegisterPatch($filepath, $packageName, $description, null, null, $this->io);

    expect($this->command->installTriggered)->toBeTrue();
});

it('does not trigger composer install when --apply is not used', function () {
    $this->command->setApplyPatches(false);

    $filepath = 'patches/test.patch';
    $packageName = 'drupal/core';
    $patchName = 'Test Patch';
    $issueId = '12345';
    $description = "Issue $issueId: $patchName";

    $this->command->testRegisterPatch($filepath, $packageName, $description, null, null, $this->io);

    expect($this->command->installTriggered)->toBeFalse();
});

it('handles composer install failure', function () {
    $this->command->mockTrigger = false; // Execute real logic with mocked executor
    $this->command->setApplyPatches(true);

    // Mock Application and Command
    $application = $this->createMock(Application::class);
    $application->method('getHelperSet')->willReturn(new HelperSet());

    $installCommand = $this->createMock(Command::class);
    $installCommand->expects($this->once())
        ->method('run')
        ->willReturn(1); // Return failure code

    $application->method('find')->with('install')->willReturn($installCommand);
    $this->command->setApplication($application);
    $this->command->setInput($this->createMock(InputInterface::class));

    // Create a NEW mock for this test to have clean expectations
    $io = $this->createMock(IOInterface::class);
    $io->method('write'); // Allow other writes
    $io->expects($this->once())
        ->method('writeError')
        ->with('<error>Failed to apply patches.</error>');

    $this->command->testTriggerComposerInstall($io);
});

it('fails to update when composer.json is missing', function () {
    // Point to non-existent file
    $missingComposerJson = sys_get_temp_dir().'/missing_'.uniqid().'.json';
    putenv('COMPOSER='.$missingComposerJson);

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->any())
        ->method('writeError')
        ->with($this->stringContains('composer.json not found'));

    $result = $this->command->testRegisterPatch('patch.patch', 'pkg', 'Issue 123: desc', null, null, $io);

    expect($result)->toBe(1);
});

it('fails to update when composer.json is invalid', function () {
    file_put_contents($this->tempComposerJson, '{invalid');

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->any())
        ->method('writeError')
        ->with($this->stringContains('Failed to parse composer.json'));

    $result = $this->command->testRegisterPatch('patch.patch', 'pkg', 'Issue 123: desc', null, null, $io);

    expect($result)->toBe(1);
});

it('uses patches-relock and patches-repatch when available', function () {
    $this->command->mockTrigger = false;
    $this->command->setApplyPatches(true);

    // Mock Application and Commands
    $application = $this->createMock(Application::class);
    $application->method('getHelperSet')->willReturn(new HelperSet());
    $application->method('has')->with('patches-relock')->willReturn(true);

    $relockCommand = $this->createMock(Command::class);
    $relockCommand->expects($this->once())->method('run')->willReturn(0);

    $repatchCommand = $this->createMock(Command::class);
    $repatchCommand->expects($this->once())->method('run')->willReturn(0);

    $application->method('find')->willReturnMap([
        ['patches-relock', $relockCommand],
        ['patches-repatch', $repatchCommand],
    ]);

    $this->command->setApplication($application);
    $this->command->setInput($this->createMock(InputInterface::class));

    // We expect writes, but order/exactness is less critical than the execute calls above.
    // Just ensuring it doesn't crash on write.
    $this->io->method('write');

    $this->command->testTriggerComposerInstall($this->io);
});

it('throws exception if package missing in non-interactive mode', function () {
    $this->command->isInstalled = false;

    // Non-interactive run
    $this->tester->execute(['--apply' => true], ['interactive' => false]);

})->throws(\RuntimeException::class, 'cweagans/composer-patches is not detected');

it('asks to install if package missing in interactive mode', function () {
    $this->command->isInstalled = false;

    // Mock IO
    // We need to recreate the IO mock here because the one in beforeEach is bound to the command
    // but CommandTester might override it or we need specific expectations.
    // Actually, CommandTester uses its own IO, but the command uses $this->getIO().
    // BasePatchCommand uses $this->getIO().
    // In TestBasePatchCommand, we didn't override getIO, so it uses the one set via setIO or HelperSet.
    // CommandTester sets the IO on the command.
    // However, our TestBasePatchCommand doesn't expose the IO set by CommandTester easily if we want to mock methods on it.
    // But wait, BasePatchCommand calls $this->getIO().
    // If we use CommandTester, it handles input/output streams.
    // But BasePatchCommand::interact calls $io->askConfirmation.
    // CommandTester provides a way to set inputs (answers).

    // The original PreFlight test mocked IO methods.
    // To support that, we need to inject our mock IO into the command and ensure CommandTester uses it or we bypass CommandTester's IO for those calls.
    // But CommandTester is designed to test interaction via streams.
    // Let's look at how PreFlight test did it: it called $this->command->setIO($this->io).
    // And then $this->tester->execute().
    // CommandTester::execute sets the input/output on the command, overwriting setIO if not careful,
    // BUT BaseCommand (Composer's) stores IO in a property.
    // Let's just set the IO on the command again.

    $this->command->setIO($this->io);

    $this->io->expects($this->once())
        ->method('askConfirmation')
        ->with('Would you like to install cweagans/composer-patches now? [Y/n] ', true)
        ->willReturn(true);

    // Interactive run
    // We pass interactive => true to execute, but since we mocked IO, the IO decides behavior.
    // However, BasePatchCommand checks $input->isInteractive().
    $this->tester->execute([], ['interactive' => true]);

    expect($this->command->installCalled)->toBeTrue();
});

it('warns if user declines to install package', function () {
    $this->command->isInstalled = false;
    $this->command->setIO($this->io);

    $this->io->expects($this->once())
        ->method('askConfirmation')
        ->with('Would you like to install cweagans/composer-patches now? [Y/n] ', true)
        ->willReturn(false);

    $this->tester->execute([], ['interactive' => true]);

    expect($this->command->installCalled)->toBeFalse();
});

it('does not ask if package installed', function () {
    $this->command->isInstalled = true;
    $this->command->setIO($this->io);

    $this->io->expects($this->never())->method('askConfirmation');

    $this->tester->execute([], ['interactive' => true]);

    expect($this->command->installCalled)->toBeFalse();
});

it('registers command in application', function () {
    $application = new Application();
    $application->addCommands([$this->command]);

    expect($application->has('test:patch'))->toBeTrue();
});

it('falls back to composer install when patches-relock is missing', function () {
    $this->command->mockTrigger = false;
    $this->command->setApplyPatches(true);

    // Mock Application
    $application = $this->createMock(Application::class);
    $application->method('getHelperSet')->willReturn(new HelperSet());
    $application->method('has')->with('patches-relock')->willReturn(false);
    $this->command->setApplication($application);

    $installCommand = $this->createMock(Command::class);
    $installCommand->expects($this->once())->method('run')->willReturn(0);
    $application->method('find')->with('install')->willReturn($installCommand);

    $this->command->setInput($this->createMock(InputInterface::class));

    $this->io->method('write');

    $this->command->testTriggerComposerInstall($this->io);
});

it('silently returns from runNormalizeIfAvailable when application has() throws', function () {
    $application = $this->createMock(Application::class);
    $application->method('has')->willThrowException(new \RuntimeException('Application not initialised'));
    $this->command->setApplication($application);

    $io = $this->createMock(IOInterface::class);
    $io->expects($this->never())->method('writeError');

    // Should not throw
    $this->command->testRunNormalize($io);
});

it('attempts normalize when the normalize command is registered', function () {
    $application = $this->createMock(Application::class);
    $application->method('has')->with('normalize')->willReturn(true);
    $this->command->setApplication($application);

    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->method('writeError'); // May be called if `composer normalize` is unavailable in test env

    // Must not throw regardless of whether `composer normalize` succeeds or fails
    $this->command->testRunNormalize($io);
});

it('returns 1 and leaves composer.json unchanged when validation fails', function () {
    $controllable = new ControllablePatchValidator();
    $controllable->shouldPass = false;
    $this->command->setValidatorOverride($controllable);

    $originalContent = file_get_contents($this->tempComposerJson);

    $result = $this->command->testRegisterPatch(
        'patches/test.patch',
        'drupal/core',
        'Issue 123: Test',
        null,
        null,
        $this->io
    );

    expect($result)->toBe(1);
    expect(file_get_contents($this->tempComposerJson))->toBe($originalContent);
});

it('deletes the patch file when validation fails', function () {
    $patchFile = sys_get_temp_dir().'/failing_'.uniqid().'.patch';
    file_put_contents($patchFile, 'not a real patch');

    $controllable = new ControllablePatchValidator();
    $controllable->shouldPass = false;
    $this->command->setValidatorOverride($controllable);

    $this->command->testRegisterPatch(
        $patchFile,
        'drupal/core',
        'Issue 123: Test',
        null,
        null,
        $this->io
    );

    expect(file_exists($patchFile))->toBeFalse();
});

it('warns when the same patch file is already registered for a different package', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Existing patch', 'url' => 'patches/shared.patch'],
                ],
            ],
        ],
    ]));

    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->expects($this->atLeastOnce())->method('writeError')
        ->with($this->stringContains('already registered'));

    $result = $this->command->testRegisterPatch(
        'patches/shared.patch',
        'drupal/views',
        'Another patch',
        null,
        null,
        $io
    );

    expect($result)->toBe(0);
});

it('warns when the same patch file is re-registered under a different description for the same package', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Original description', 'url' => 'patches/core.patch'],
                ],
            ],
        ],
    ]));

    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->expects($this->atLeastOnce())->method('writeError')
        ->with($this->stringContains('already registered'));

    $result = $this->command->testRegisterPatch(
        'patches/core.patch',
        'drupal/core',
        'Different description',
        null,
        null,
        $io
    );

    expect($result)->toBe(0);
});

it('does not warn when updating an existing entry with the same description', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Same description', 'url' => 'patches/core.patch'],
                ],
            ],
        ],
    ]));

    $io = $this->createMock(IOInterface::class);
    $io->method('write');
    $io->expects($this->never())->method('writeError');

    $result = $this->command->testRegisterPatch(
        'patches/core.patch',
        'drupal/core',
        'Same description',
        null,
        null,
        $io
    );

    expect($result)->toBe(0);
});

it('does not delete a missing patch file when validation fails', function () {
    $controllable = new ControllablePatchValidator();
    $controllable->shouldPass = false;
    $this->command->setValidatorOverride($controllable);

    // Should not throw even if the file path does not exist on disk
    $result = $this->command->testRegisterPatch(
        '/tmp/nonexistent_patch_file.patch',
        'drupal/core',
        'Issue 123: Test',
        null,
        null,
        $this->io
    );

    expect($result)->toBe(1);
});
