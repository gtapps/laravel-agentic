<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\Approvals\Approval;
use Gtapps\LaravelAgentic\Approvals\ApprovalBroker;

/**
 * An approval key omits the user id by design, so it addresses one knock per
 * principal. When it addresses several, agentic:approve/deny refuse and show
 * the operator what to pick with --id, rather than settling an arbitrary one.
 *
 * @internal
 */
trait ResolvesApprovalKey
{
    protected function reportUnresolved(ApprovalBroker $broker, string $key, ?string $approvalId): int
    {
        $pending = $broker->pendingFor($key);

        if ($pending->isEmpty()) {
            $this->error('No pending approval found for that key (it may have expired).');

            return self::FAILURE;
        }

        // Reachable with one candidate too, when --id simply didn't match it —
        // so name the id as the problem rather than repeating the --id advice.
        $this->error($approvalId !== null
            ? "That key has no pending approval with id {$approvalId}."
            : "That key matches {$pending->count()} pending approvals from different principals.");

        $this->line('Choose one with --id:');

        $this->table(
            ['id', 'user', 'surface', 'requested'],
            $pending->map(fn (Approval $approval) => [
                $approval->id,
                $approval->requested_user_id ?? '(none)',
                $approval->requested_surface,
                $approval->created_at->diffForHumans(),
            ]),
        );

        return self::FAILURE;
    }
}
