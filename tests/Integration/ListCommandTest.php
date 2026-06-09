<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bounteous\Darn\Command\ListCommand;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestServiceFactory;

class TestListCommand extends ListCommand
{
    public IOInterface $ioMock;

    public function getIO(): IOInterface
    {
        return $this->ioMock;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Bypass Composer\Command\BaseCommand::initialize() which would eagerly
        // parse composer.json, throwing before execute() can handle error conditions.
        $this->input = $input;
        $this->output = $output;
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/darn_list_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->tempComposerJson = $this->tempDir.'/composer.json';
    putenv('COMPOSER='.$this->tempComposerJson);

    $this->io = $this->createMock(IOInterface::class);
    $this->command = new TestListCommand();
    $this->command->ioMock = $this->io;
    $this->command->setApplication(new Application());
    $this->sf = new TestServiceFactory($this->io);
    $this->command->setServiceFactory($this->sf);
    $this->tester = new CommandTester($this->command);

    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalCwd);
    putenv('COMPOSER');

    if (is_dir($this->tempDir)) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            @($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
        }
        @rmdir($this->tempDir);
    }
});

it('reports no patches when composer.json has no patches key', function () {
    file_put_contents($this->tempComposerJson, json_encode(['name' => 'test/project']));

    $this->io->expects($this->once())->method('write')
        ->with($this->matchesRegularExpression('/No patches found/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);
});

it('lists patches grouped by package with file status indicators', function () {
    mkdir('patches', 0777, true);
    file_put_contents('patches/existing.patch', 'content');

    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Existing patch', 'url' => 'patches/existing.patch'],
                    ['description' => 'Missing patch', 'url' => 'patches/missing.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $result = $this->tester->execute([]);

    expect($result)->toBe(0);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('drupal/core');
    expect($output)->toContain('✓');
    expect($output)->toContain('✗');
    expect($output)->toContain('Existing patch');
    expect($output)->toContain('Missing patch');
});

it('prints a summary line with patch and package totals', function () {
    mkdir('patches', 0777, true);
    file_put_contents('patches/fix.patch', 'content');

    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Fix', 'url' => 'patches/fix.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('1 patch(es)');
    expect($output)->toContain('1 package(s)');
});

it('mentions missing count in summary when files are absent', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Gone', 'url' => 'patches/gone.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('missing');
});

it('filters output to a specific package', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Core patch', 'url' => 'patches/core.patch'],
                ],
                'drupal/views' => [
                    ['description' => 'Views patch', 'url' => 'patches/views.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute(['package' => 'drupal/core']);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('drupal/core');
    expect($output)->not->toContain('drupal/views');
});

it('reports no patches found for an unknown package argument', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Core patch', 'url' => 'patches/core.patch'],
                ],
            ],
        ],
    ]));

    $this->io->expects($this->once())->method('write')
        ->with($this->matchesRegularExpression('/No patches found/'));

    $result = $this->tester->execute(['package' => 'drupal/nonexistent']);

    expect($result)->toBe(0);
});

it('fails gracefully when composer.json is missing', function () {
    $this->io->expects($this->once())->method('writeError')
        ->with($this->matchesRegularExpression('/composer.json not found/'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(1);
});

it('fails gracefully when composer.json is invalid', function () {
    file_put_contents($this->tempComposerJson, '{invalid');

    $this->io->expects($this->once())->method('writeError')
        ->with($this->stringContains('does not contain valid JSON'));

    $result = $this->tester->execute([]);

    expect($result)->toBe(1);
});

it('recognises legacy string-keyed entries', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    'Legacy patch description' => 'patches/legacy.patch',
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('Legacy patch description');
    expect($output)->toContain('patches/legacy.patch');
});

it('sorts packages alphabetically', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/views' => [
                    ['description' => 'Views patch', 'url' => 'patches/views.patch'],
                ],
                'drupal/core' => [
                    ['description' => 'Core patch', 'url' => 'patches/core.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $packageLines = array_values(array_filter($writeCalls, fn ($line) => str_contains($line, 'drupal/')));
    expect($packageLines[0])->toContain('drupal/core');
    expect($packageLines[1])->toContain('drupal/views');
});

it('displays the ticket reference inline when set', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    [
                        'description' => 'Fix bug',
                        'url' => 'patches/fix.patch',
                        'extra' => ['ticket' => 'JIRA-123'],
                    ],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $output = implode("\n", $writeCalls);
    expect($output)->toContain('JIRA-123');
    expect($output)->toContain('Fix bug');
});

it('does not show a ticket bracket when ticket is absent', function () {
    file_put_contents($this->tempComposerJson, json_encode([
        'extra' => [
            'patches' => [
                'drupal/core' => [
                    ['description' => 'Fix bug', 'url' => 'patches/fix.patch'],
                ],
            ],
        ],
    ]));

    $writeCalls = [];
    $this->io->method('write')->willReturnCallback(function ($msg) use (&$writeCalls) {
        $writeCalls[] = $msg;
    });

    $this->tester->execute([]);

    $output = implode("\n", $writeCalls);
    // Status indicators [✓] / [✗] are expected; a ticket bracket after the
    // description would look like "Fix bug [PROJ-…]" — that must not appear.
    expect($output)->not->toMatch('/Fix bug \[/');
});
