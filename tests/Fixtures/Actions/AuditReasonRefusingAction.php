<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Exceptions\ActionRefused;

#[AgentAction(
    name: 'audit-reason-refusing',
    description: 'Refuses with a caller message that must stay out of the audit row.',
)]
class AuditReasonRefusingAction
{
    public function handle(PingInput $input): never
    {
        throw new ActionRefused(
            "Version conflict. The newer version follows:\nsecret body",
            'conflict',
            auditReason: 'Version conflict.',
        );
    }
}
