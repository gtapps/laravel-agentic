<?php

namespace Gtapps\LaravelAgentic\Tests\Fixtures\Actions;

use Gtapps\LaravelAgentic\Attributes\AgentAction;
use Gtapps\LaravelAgentic\Enums\Surface;
use Illuminate\Auth\Access\Response;

/**
 * Every bool-or-Response shape in one action. Audited despite being readOnly so
 * the audit tests can assert what a concealed denial records.
 */
#[AgentAction(
    name: 'response-gate',
    description: 'Authorizes with Gate responses.',
    readOnly: true,
    audit: true,
    surfaces: [Surface::Mcp, Surface::Http, Surface::Cli, Surface::Job],
)]
class ResponseGateAction
{
    public function authorizeMcp(): Response
    {
        return Response::allow();
    }

    public function authorizeHttp(): Response
    {
        return Response::denyAsNotFound('invoice 42 belongs to another tenant');
    }

    public function authorizeCli(): Response
    {
        return Response::deny('Refunds are disabled during close.');
    }

    public function authorizeJob(): Response
    {
        // Denied with no message at all — the caller keeps the default wording.
        return Response::deny();
    }

    public function handle(PingInput $input): string
    {
        return 'response '.$input->message;
    }
}
