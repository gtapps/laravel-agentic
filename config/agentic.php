<?php

return [

    /*
    | Directories scanned for #[AgentAction] classes. Package-provided
    | actions register via Agentic::register([...]) instead; scanned (app)
    | actions override registered ones by name.
    */
    'discovery' => [
        'paths' => [
            app_path('Actions'),
        ],
    ],

    /*
    | Auto-mounted HTTP surface: POST /{prefix}/actions/{name} (GET allowed
    | for readOnly actions). Put your auth middleware here — e.g.
    | ['api', 'auth:sanctum'].
    */
    'http' => [
        'enabled' => true,
        'prefix' => 'agentic',
        'middleware' => ['api'],
    ],

    /*
    | Tiered MCP exposure: unauthenticated callers only see (and can
    | only call) the allowlisted action names; 'exclude' is a hard denylist
    | that beats everything. Both gate tools/list AND tools/call.
    */
    'mcp' => [
        'tiers' => [
            'unauthenticated' => [],
        ],
        'exclude' => [],
    ],

    /*
    | Pending approvals expire to DENY after this many seconds; the agent
    | must knock again.
    */
    'approvals' => [
        'ttl' => 600,
    ],

    /*
    | Dot-path globs redacted from BOTH audit rows and approval payloads,
    | e.g. 'password', '*.password', 'card.secret'.
    */
    'redact' => [],

    /*
    | Master switch for the agentic_action_log recorder. Per-action opt-out
    | via #[AgentAction(audit: false)].
    */
    'audit' => [
        'enabled' => true,
    ],

];
