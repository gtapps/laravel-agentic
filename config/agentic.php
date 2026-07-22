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
    | Opt-in HTTP surface: POST /{prefix}/actions/{name} (GET allowed for
    | readOnly actions). Off by default — an action with no authorize()
    | would otherwise be anonymously reachable. Set 'enabled' => true and
    | put your auth middleware here — e.g. ['api', 'auth:sanctum'] — before
    | exposing it. authorize() remains the standing gate on every surface.
    */
    'http' => [
        'enabled' => env('AGENTIC_HTTP_ENABLED', false),
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
        'ttl' => env('AGENTIC_APPROVALS_TTL', 600),
        'connection' => env('AGENTIC_APPROVALS_CONNECTION'),
    ],

    /*
    | Dot-path globs redacted from BOTH audit rows and approval payloads,
    | e.g. 'password', '*.password', 'card.secret'.
    */
    'redact' => [],

    /*
    | Master switch for the agentic_action_log recorder. Non-readOnly actions
    | audit by default; opt out per-action via #[AgentAction(audit: false)].
    | readOnly actions are excluded by default — opt in with #[AgentAction(audit: true)].
    */
    'audit' => [
        'enabled' => env('AGENTIC_AUDIT_ENABLED', true),
        'connection' => env('AGENTIC_AUDIT_CONNECTION'),
    ],

];
