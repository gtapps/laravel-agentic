<?php

namespace Gtapps\LaravelAgentic\Surfaces\Http;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @internal
 */
class ActionController
{
    public function __invoke(
        Request $request,
        string $name,
        Registry $registry,
        AgenticManager $agentic,
        ContextFactory $contexts,
    ): JsonResponse {
        $action = $registry->find($name);

        if ($request->isMethod('GET') && ! $action?->readOnly) {
            // GET is allowed for readOnly actions only; reads may not
            // mutate. 405 only for an action actually reachable over HTTP —
            // an action not exposed here stays a 404 so a GET can't leak the
            // existence of a hidden action that a POST reports as unknown.
            abort($action?->exposedTo(Surface::Http) ? 405 : 404);
        }

        $args = $request->isMethod('GET') ? $request->query() : $request->all();

        $context = $contexts->make(Surface::Http, $request->user());

        try {
            $result = $agentic->run($name, $args, $context);
        } catch (ActionNotFound $e) {
            abort(404, $e->getMessage());
        } catch (ActionDenied $e) {
            abort(403, $e->getMessage());
        } catch (ApprovalRequiredException $e) {
            return new JsonResponse([
                'status' => 'approval_required',
                'key' => $e->key,
                'approvalId' => $e->approvalId,
                'retry' => 'identical call after approval',
                'message' => $e->getMessage(),
            ], 409);
        }

        return new JsonResponse([
            'status' => 'ok',
            'result' => $result->value,
        ]);
    }
}
