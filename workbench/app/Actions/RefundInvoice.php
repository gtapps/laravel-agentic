<?php

namespace Workbench\App\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Support\Facades\Gate;

#[AgentAction(
    name: 'refund-invoice',
    description: 'Refund an invoice to the original payment method.',
    readOnly: false,
    needsApproval: true,
    surfaces: [Surface::Mcp, Surface::AiTool, Surface::Http, Surface::Cli, Surface::Job],
    agentInputSchema: CompactRefundInput::class,
    outputSchema: RefundResult::class,
)]
class RefundInvoice
{
    public function authorize(ActionContext $ctx, RefundInvoiceInput $input): bool
    {
        return Gate::forUser($ctx->user())->allows('refund-invoice', $input->invoiceId);
    }

    public function handle(RefundInvoiceInput $input, ActionContext $ctx): RefundResult
    {
        return new RefundResult(
            invoiceId: $input->invoiceId,
            amount: $input->amount,
            status: 'refunded',
        );
    }
}
