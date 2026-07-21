<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;
use Illuminate\Console\Command;

class DenyCommand extends Command
{
    use ResolvesApprovalKey;

    protected $signature = 'agentic:deny
        {key : The approval key shown to the agent}
        {--id= : Which knock to deny, when the key matches more than one}';

    protected $description = 'Deny a pending agentic approval';

    public function handle(ApprovalBroker $broker): int
    {
        $key = $this->argument('key');
        $id = $this->option('id');

        if (! $broker->decideViaArtisan($key, approve: false, approvalId: $id)) {
            return $this->reportUnresolved($broker, $key, $id);
        }

        $this->info('Denied.');

        return self::SUCCESS;
    }
}
