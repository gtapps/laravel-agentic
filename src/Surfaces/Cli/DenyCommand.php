<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Illuminate\Console\Command;

class DenyCommand extends Command
{
    protected $signature = 'agentic:deny {key : The approval key shown to the agent}';

    protected $description = 'Deny a pending agentic approval';

    public function handle(ApprovalBroker $broker): int
    {
        if (! $broker->decideViaArtisan($this->argument('key'), approve: false)) {
            $this->error('No pending approval found for that key (it may have expired).');

            return self::FAILURE;
        }

        $this->info('Denied.');

        return self::SUCCESS;
    }
}
