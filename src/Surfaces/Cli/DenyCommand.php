<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Illuminate\Console\Command;

class DenyCommand extends Command
{
    protected $signature = 'agentic:deny {id : The approval id shown to the agent}';

    protected $description = 'Deny a pending agentic approval';

    public function handle(ApprovalBroker $broker): int
    {
        if (! $broker->decideViaArtisan($this->argument('id'), approve: false)) {
            $this->error('No pending approval found for that id (it may have expired).');

            return self::FAILURE;
        }

        $this->info('Denied.');

        return self::SUCCESS;
    }
}
