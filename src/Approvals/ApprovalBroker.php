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
        $this->expireStale('args_hash', $key);

        $approval = $this->findGranted($definition, $key, $context);

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
     * The row for one native invocation in whatever state it reached, so a
     * caller mapping a paused run can tell "refused" from "not answered yet" —
     * a distinction the grant-only reads above deliberately cannot make.
     *
     * Expires first, like every other read here: nothing else on the native
     * path is keyed on args_hash, so without this an unanswered knock would
     * read as 'pending' forever and the paused run would poll indefinitely
     * instead of expiring to deny.
     */
    public function stateFor(string $invocationKey): ?Approval
    {
        $this->expireStale('invocation_key', $invocationKey);

        return Approval::query()
            ->where('invocation_key', $invocationKey)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The one predicate for "a grant this caller may use", shared so the
     * consuming and non-consuming readers can never drift apart — a filter
     * added to one and forgotten on the other would let peek() report a grant
     * the gate would refuse, or worse, the reverse.
     */
    protected function findGranted(ActionDefinition $definition, string $key, ActionContext $context): ?Approval
    {
        return Approval::query()
            ->where('args_hash', $key)
            ->where('status', 'granted')
            ->where('definition_hash', $definition->definitionHash)
            ->tap(fn (Builder $q) => $this->wherePrincipal($q, $context))
            ->tap(fn (Builder $q) => $this->whereInvocation($q, $context))
            ->first();
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
        $invocationKey = self::invocationKey($context);
        $activeKey = self::activeKey($key, $userId, $invocationKey);

        // One tool call gets exactly one approval, for its whole life. settle()
        // clears active_key, so the pending lookup below stops matching the
        // moment a row is granted or denied — a caller that asks again would
        // slip past the unique index and mint a SECOND pending row for consent
        // a human already gave or refused. ApprovalGate happens to consume
        // before it ever re-asks, but callers outside the gate (mapping a
        // paused run's decisions) have no such ordering, so the guard belongs
        // here rather than in their call order.
        if ($invocationKey !== null) {
            $existing = Approval::query()->where('invocation_key', $invocationKey)->orderByDesc('id')->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // active_key already IS the (key, principal, invocation) identity while
        // pending, so it doubles as the idempotency lookup here.
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
            'invocation_key' => $invocationKey,
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
     * Expiry → deny, enforced lazily — no scheduler in v1. Clears active_key
     * so an expired pending row never blocks a fresh knock for the same
     * (key, principal).
     *
     * Both read paths reach it by whichever column identifies their row —
     * args_hash for the retry-based flow, invocation_key for the native one —
     * so the expiry rule can never be changed for one and missed for the other.
     */
    protected function expireStale(string $column, string $value): void
    {
        Approval::query()
            ->where($column, $value)
            ->whereIn('status', ['pending', 'granted'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'active_key' => null]);
    }

    /**
     * The pending-uniqueness identity. Callers that carry an invocation (a
     * laravel/ai tool call) get one pending row PER invocation; everyone else
     * keeps the historical one-row-per-(args, principal) behaviour, and their
     * hash is byte-identical to what it was before invocations existed.
     */
    protected static function activeKey(string $key, string|int|null $userId, ?string $invocationKey = null): string
    {
        $identity = $key.'|'.($userId ?? '');

        if ($invocationKey !== null) {
            $identity .= '|'.$invocationKey;
        }

        return hash('sha256', $identity);
    }

    /**
     * Correlation identity for one native tool call. Null for surfaces that
     * have no stable per-invocation id, which is what keeps their queries
     * (and their semantics) exactly as they were.
     */
    public static function invocationKey(ActionContext $context): ?string
    {
        $toolCallId = $context->idempotencyKey();

        if ($toolCallId === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            $context->caller()->value,
            (string) ($context->user()?->getAuthIdentifier() ?? ''),
            $toolCallId,
        ]));
    }

    /**
     * A grant is only usable by the invocation it was issued for. Rows with no
     * invocation stay usable by anyone matching the args and principal, so a
     * knock raised on one surface can still be approved and consumed on
     * another — the cross-surface promise the broker exists to keep.
     */
    protected function whereInvocation(Builder $query, ActionContext $context): void
    {
        $invocationKey = self::invocationKey($context);

        $invocationKey === null
            ? $query->whereNull('invocation_key')
            : $query->where(fn (Builder $q) => $q->whereNull('invocation_key')->orWhere('invocation_key', $invocationKey));
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
