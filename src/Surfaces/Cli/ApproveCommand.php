<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Illuminate\Console\Command;

class ApproveCommand extends Command
{
    protected $signature = 'agentic:approve {key : The approval key shown to the agent}';

    protected $description = 'Grant a pending agentic approval (trusted local, no token)';

    public function handle(ApprovalBroker $broker): int
    {
        if (! $broker->decideViaArtisan($this->argument('key'), approve: true)) {
            $this->error('No pending approval found for that key (it may have expired).');

            return self::FAILURE;
        }

        $this->info('Approved. The agent can now retry the identical call once.');

        return self::SUCCESS;
    }
}
