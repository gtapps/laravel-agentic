<?php

namespace Gtapps\LaravelAgentic\Approvals;

use Gtapps\LaravelAgentic\Contracts\ActionContext;
use Gtapps\LaravelAgentic\Events\ApprovalDenied;
use Gtapps\LaravelAgentic\Events\ApprovalGranted;
use Gtapps\LaravelAgentic\Events\ApprovalRequested;
use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retry-based approvals: single-use grants keyed on
 * hash(action + canonical args), bound to the requesting principal,
 * and expiring to deny.
 *
 * Terminal rows (denied/expired/consumed) are never pruned in v1 —
 * agentic_approvals grows without bound. Fine at typical approval volumes;
 * revisit with a retention job if that changes.
 */
class ApprovalBroker
{
    public function __construct(protected Repository $config) {}

    /**
     * Granted consumes atomically: select the grant, then flip it with a
     * PK-scoped conditional UPDATE so two concurrent callers can't both
     * consume it. Returns the consumed approval (for audit linkage) or null.
     * A grant is void if the definition changed since approval or was
     * requested by a different principal.
     */
    public function check(ActionDefinition $definition, string $key, ActionContext $context): ?Approval
    {
        $this->expireStale($key);

        $approval = Approval::query()
            ->where('args_hash', $key)
            ->where('status', 'granted')
            ->where('definition_hash', $definition->definitionHash)
            ->tap(fn (Builder $q) => $this->wherePrincipal($q, $context))
            ->first();

        if ($approval === null) {
            return null;
        }

        $consumedAt = now();

        $consumed = Approval::query()
            ->whereKey($approval->getKey())
            ->where('status', 'granted')
            ->update(['status' => 'consumed', 'consumed_at' => $consumedAt]);

        if ($consumed === 0) {
            return null;
        }

        $approval->status = 'consumed';
        $approval->consumed_at = $consumedAt;

        return $approval;
    }

    /**
     * Idempotent per (key, principal) while pending. Returns the pending
     * approval; fires ApprovalRequested with the plaintext capability token
     * (stored only as a sha256 hash) on first creation.
     */
    public function requestApproval(
        ActionDefinition $definition,
        string $key,
        array $argsRedacted,
        ActionContext $context,
    ): Approval {
        $existing = Approval::query()
            ->where('args_hash', $key)
            ->where('status', 'pending')
            ->tap(fn (Builder $q) => $this->wherePrincipal($q, $context))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $approval = Approval::create([
            'action' => $definition->name,
            'args_hash' => $key,
            'args_redacted' => $argsRedacted,
            'status' => 'pending',
            'token_hash' => hash('sha256', $token),
            'requested_user_id' => $context->user()?->getAuthIdentifier(),
            'requested_surface' => $context->caller()->value,
            'definition_hash' => $definition->definitionHash,
            'expires_at' => now()->addSeconds((int) $this->config->get('agentic.approvals.ttl', 600)),
        ]);

        event(new ApprovalRequested($approval, $token));

        return $approval;
    }

    /**
     * Programmatic grant/deny — REQUIRES the capability token (timing-safe
     * compare). Artisan uses decideViaArtisan() instead because the local
     * process boundary is already trusted.
     */
    public function decide(string $key, string $token, bool $approve, ?string $decidedBy = null): bool
    {
        $approval = $this->findPending($key);

        if ($approval === null || ! hash_equals($approval->token_hash, hash('sha256', $token))) {
            return false;
        }

        $this->settle($approval, $approve, $decidedBy);

        return true;
    }

    /**
     * Token-free grant/deny for the agentic:approve / agentic:deny commands.
     * Anyone with artisan has tinker; the process boundary is the trust line.
     */
    public function decideViaArtisan(string $key, bool $approve): bool
    {
        $approval = $this->findPending($key);

        if ($approval === null) {
            return false;
        }

        $this->settle($approval, $approve, 'artisan');

        return true;
    }

    protected function findPending(string $key): ?Approval
    {
        $this->expireStale($key);

        return Approval::query()
            ->where('args_hash', $key)
            ->where('status', 'pending')
            ->first();
    }

    protected function settle(Approval $approval, bool $approve, ?string $decidedBy): void
    {
        $approval->update([
            'status' => $approve ? 'granted' : 'denied',
            'decided_by' => $decidedBy,
            'decided_at' => now(),
        ]);

        event($approve ? new ApprovalGranted($approval) : new ApprovalDenied($approval));
    }

    /**
     * Expiry → deny, enforced lazily — no scheduler in v1.
     */
    protected function expireStale(string $key): void
    {
        Approval::query()
            ->where('args_hash', $key)
            ->whereIn('status', ['pending', 'granted'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /**
     * Null-safe principal match: grants bound to the requesting user; the
     * user id stays OUT of the hash key so the key shown to humans is
     * stable across surfaces.
     */
    protected function wherePrincipal(Builder $query, ActionContext $context): void
    {
        $userId = $context->user()?->getAuthIdentifier();

        $userId === null
            ? $query->whereNull('requested_user_id')
            : $query->where('requested_user_id', $userId);
    }
}
