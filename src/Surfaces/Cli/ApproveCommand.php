<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Illuminate\Console\Command;

class ApproveCommand extends Command
{
    use ResolvesApprovalKey;

    protected $signature = 'agentic:approve
        {key : The approval key shown to the agent}
        {--id= : Which knock to grant, when the key matches more than one}';

    protected $description = 'Grant a pending agentic approval (trusted local, no token)';

    public function handle(ApprovalBroker $broker): int
    {
        $key = $this->argument('key');
        $id = $this->option('id');

        if (! $broker->decideViaArtisan($key, approve: true, approvalId: $id)) {
            return $this->reportUnresolved($broker, $key, $id);
        }

        $this->info('Approved. The agent can now retry the identical call once.');

        return self::SUCCESS;
    }
}
