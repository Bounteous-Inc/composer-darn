<?php

declare(strict_types=1);

use Bounteous\Darn\Command\DirectPatchCommand;
use Bounteous\Darn\Command\DispatchCommand;
use Bounteous\Darn\Command\DrupalOrgPatchCommand;
use Bounteous\Darn\Command\FixCommand;
use Bounteous\Darn\Command\GithubCommand;
use Bounteous\Darn\Command\ListCommand;
use Bounteous\Darn\Command\PruneCommand;
use Bounteous\Darn\Command\RemoveCommand;
use Bounteous\Darn\Command\VerifyCommand;
use Bounteous\Darn\CommandProvider;
use Symfony\Component\Console\Application;

it('provides all darn commands', function () {
    $provider = new CommandProvider();
    $commands = $provider->getCommands();

    expect($commands)->toHaveCount(9);
    expect($commands[0])->toBeInstanceOf(DispatchCommand::class);
    expect($commands[1])->toBeInstanceOf(DirectPatchCommand::class);
    expect($commands[2])->toBeInstanceOf(DrupalOrgPatchCommand::class);
    expect($commands[3])->toBeInstanceOf(FixCommand::class);
    expect($commands[4])->toBeInstanceOf(GithubCommand::class);
    expect($commands[5])->toBeInstanceOf(ListCommand::class);
    expect($commands[6])->toBeInstanceOf(RemoveCommand::class);
    expect($commands[7])->toBeInstanceOf(VerifyCommand::class);
    expect($commands[8])->toBeInstanceOf(PruneCommand::class);
});

it('registers all commands under their canonical names', function () {
    $application = new Application();
    $provider = new CommandProvider();

    foreach ($provider->getCommands() as $command) {
        $application->addCommand($command);
    }

    expect($application->find('darn'))->toBeInstanceOf(DispatchCommand::class);
    expect($application->find('darn:patch'))->toBeInstanceOf(DirectPatchCommand::class);
    expect($application->find('darn:drupal.org'))->toBeInstanceOf(DrupalOrgPatchCommand::class);
    expect($application->find('darn:fix'))->toBeInstanceOf(FixCommand::class);
    expect($application->find('darn:github'))->toBeInstanceOf(GithubCommand::class);
    expect($application->find('darn:list'))->toBeInstanceOf(ListCommand::class);
    expect($application->find('darn:remove'))->toBeInstanceOf(RemoveCommand::class);
    expect($application->find('darn:verify'))->toBeInstanceOf(VerifyCommand::class);
    expect($application->find('darn:prune'))->toBeInstanceOf(PruneCommand::class);
});
