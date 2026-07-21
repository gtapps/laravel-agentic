<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'fail-closed',
    description: 'Uses a predicate that throws — must fail closed to approval required.',
    needsApproval: ThrowingPredicate::class,
)]
class FailClosedAction
{
    public function handle(RefundishInput $input): string
    {
        return 'executed '.$input->amount;
    }
}
