<?php

namespace Gtapps\LaravelAgentic\Surfaces\Jobs;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\RequestedUserNotFound;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public string $name,
        public array $args,
        public string|int|null $userId = null,
    ) {}

    public function handle(AgenticManager $agentic, ContextFactory $contexts, AuthFactory $auth): void
    {
        $user = null;

        if ($this->userId !== null) {
            $user = $auth->guard()->getProvider()->retrieveById($this->userId);

            if ($user === null) {
                throw RequestedUserNotFound::forId($this->userId);
            }
        }

        $context = $contexts->make(Surface::Job, $user);

        try {
            $agentic->run($this->name, $this->args, $context);
        } catch (ApprovalRequiredException $e) {
            // Fail WITHOUT retry: the audit row and ApprovalRequested
            // event already fired; re-dispatching after the grant is the
            // caller's move.
            if ($this->job !== null) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
