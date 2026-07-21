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

        return $approval
            ->forceFill(['status' => 'consumed', 'consumed_at' => $consumedAt])
            ->syncOriginal();
    }

    /**
     * Idempotent per (key, principal) while pending. Returns the pending
     * approval; fires ApprovalRequested with the plaintext capability token
     * (stored only as a sha256 hash) on first creation. The decision
     * identity a human acts on is the approval's ULID, never this key —
     * `active_key` (unique, non-null only while pending) is what actually
     * enforces "one pending row per (key, principal)" against a concurrent
     * duplicate create.
     *
     * Deliberately does NOT match definition_hash, unlike check(). The broker
     * only learns the current hash from a live call, so decideViaArtisan() —
     * where the operator actually decides — cannot see drift at all; filtering
     * here would close only the narrowest timing. A knock that drifts before
     * approval is granted void and re-knocks once. check() still refuses it,
     * so nothing unsafe executes; the cost is one wasted approval inside the
     * TTL window.
     */
    public function requestApproval(
        ActionDefinition $definition,
        string $key,
        array $argsRedacted,
        ActionContext $context,
    ): Approval {
        $userId = $context->user()?->getAuthIdentifier();
        $activeKey = self::activeKey($key, $userId);

        // active_key already IS the (key, principal) identity while pending,
        // so it doubles as the idempotency lookup here.
        $existing = Approval::query()->where('active_key', $activeKey)->where('status', 'pending')->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // createOrFirst() wraps the insert in a savepoint only when already
        // nested in a transaction (so a failed insert can't poison an
        // ambient PostgreSQL transaction), and on a unique active_key
        // collision from a concurrent identical knock, refetches the
        // pending row that won instead of failing the call.
        $approval = Approval::query()->createOrFirst(['active_key' => $activeKey], [
            'action' => $definition->name,
            'args_hash' => $key,
            'args_redacted' => $argsRedacted,
            'status' => 'pending',
            'token_hash' => hash('sha256', $token),
            'requested_user_id' => $userId,
            'requested_surface' => $context->caller()->value,
            'definition_hash' => $definition->definitionHash,
            'expires_at' => now()->addSeconds((int) $this->config->get('agentic.approvals.ttl', 600)),
        ]);

        if ($approval->wasRecentlyCreated) {
            event(new ApprovalRequested($approval, $token));
        }

        return $approval;
    }

    /**
     * Programmatic grant/deny — REQUIRES the capability token (timing-safe
     * compare). Artisan uses decideViaArtisan() instead because the local
     * process boundary is already trusted.
     */
    public function decide(string $id, string $token, bool $approve, ?string $decidedBy = null): bool
    {
        $approval = $this->findPendingById($id);

        if ($approval === null || ! hash_equals($approval->token_hash, hash('sha256', $token))) {
            return false;
        }

        return $this->settle($approval, $approve, $decidedBy);
    }

    /**
     * Token-free grant/deny for the agentic:approve / agentic:deny commands.
     * Anyone with artisan has tinker; the process boundary is the trust line.
     */
    public function decideViaArtisan(string $id, bool $approve): bool
    {
        $approval = $this->findPendingById($id);

        if ($approval === null) {
            return false;
        }

        return $this->settle($approval, $approve, 'artisan');
    }

    protected function findPendingById(string $id): ?Approval
    {
        $approval = Approval::find($id);

        return $approval !== null && $approval->status === 'pending' ? $approval : null;
    }

    /**
     * Single conditional UPDATE guarded on id + status + expiry — no
     * read-then-write race with a concurrent settle/expiry on the same row.
     * Returns false (no event fired) if another decision or expiry won.
     */
    protected function settle(Approval $approval, bool $approve, ?string $decidedBy): bool
    {
        $affected = Approval::query()
            ->where('id', $approval->id)
            ->where('status', 'pending')
            ->where('expires_at', '>=', now())
            ->update([
                'status' => $approve ? 'granted' : 'denied',
                'active_key' => null,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
            ]);

        if ($affected === 0) {
            return false;
        }

        $approval->refresh();

        event($approve ? new ApprovalGranted($approval) : new ApprovalDenied($approval));

        return true;
    }

    /**
     * Expiry → deny, enforced lazily — no scheduler in v1. Clears
     * active_key so an expired pending row never blocks a fresh knock for
     * the same (key, principal).
     */
    protected function expireStale(string $key): void
    {
        Approval::query()
            ->where('args_hash', $key)
            ->whereIn('status', ['pending', 'granted'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'active_key' => null]);
    }

    protected static function activeKey(string $key, string|int|null $userId): string
    {
        return hash('sha256', $key.'|'.($userId ?? ''));
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
