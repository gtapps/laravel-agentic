<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;

#[AgentAction(
    name: 'predicate-refund',
    description: 'Needs approval only above 100, decided by an invokable predicate.',
    needsApproval: CheapCallPredicate::class,
)]
class PredicateAction
{
    public function handle(RefundishInput $input): string
    {
        return 'executed '.$input->amount;
    }
}
