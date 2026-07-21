# Working with this application's actions

This application exposes governed **actions** to agents through
laravel-agentic. Read this before calling any tool.

## Discovering actions

- Over MCP: `tools/list` returns every action you are allowed to see. The
  set depends on who you are authenticated as — do not assume a tool that
  is missing for you does not exist.
- Each tool's `description` is written for you, the model. Its
  `inputSchema` is authoritative: send exactly those fields. Extra fields
  are rejected.

## Calling actions

- Arguments are validated server-side against a stricter schema than the
  one you see. If validation fails you get field-keyed errors — fix the
  named fields and retry.
- Actions marked `readOnly` have no side effects; you may call them freely
  to inspect state.
- Every non-readOnly call you make is audited: who called, via which
  surface, with what arguments, and what happened.

## The approval protocol (important)

Some actions require per-invocation human consent. The first call does NOT
execute; it returns an error like:

> Approval required for action 'refund-invoice'. Pending under key
> `<64-hex-key>`. Ask a human to run: `php artisan agentic:approve <key>`.
> Then retry this exact call unchanged.

What you must do:

1. Surface the key and the approve command to the human. Do not guess or
   fabricate keys.
2. Wait for the human to confirm they approved.
3. Retry the **identical call with identical arguments**. Any change in
   arguments produces a different key and knocks again.
4. Grants are single-use and expire (default 10 minutes). If your retry
   knocks again, the grant was consumed, expired, or the action definition
   changed — ask the human to approve the new key.

Never attempt to bypass an approval by rephrasing arguments, switching
surfaces, or calling adjacent actions to achieve the same effect.
