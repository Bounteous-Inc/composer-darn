<?php

declare(strict_types=1);

namespace Bounteous\Darn;

use Bounteous\Darn\Command\DirectPatchCommand;
use Bounteous\Darn\Command\DispatchCommand;
use Bounteous\Darn\Command\DrupalOrgPatchCommand;
use Bounteous\Darn\Command\FixCommand;
use Bounteous\Darn\Command\GithubCommand;
use Bounteous\Darn\Command\ListCommand;
use Bounteous\Darn\Command\PruneCommand;
use Bounteous\Darn\Command\RemoveCommand;
use Bounteous\Darn\Command\VerifyCommand;
use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers all darn:* Composer console commands with the Composer application.
 *
 * Composer's PluginManager calls new CommandProvider(['composer' => ..., 'io' => ..., 'plugin' => ...]).
 * No constructor is declared here because the commands access Composer and IO at runtime via
 * BaseCommand::getComposer() / getIO(), so there is nothing to store at construction time.
 * PHP silently accepts the array argument when no constructor is defined.
 */
class CommandProvider implements CommandProviderCapability
{
    /**
     * Returns the list of commands provided by this plugin.
     *
     * @return array<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new DispatchCommand(),
            new DirectPatchCommand(),
            new DrupalOrgPatchCommand(),
            new FixCommand(),
            new GithubCommand(),
            new ListCommand(),
            new RemoveCommand(),
            new VerifyCommand(),
            new PruneCommand(),
        ];
    }
}
