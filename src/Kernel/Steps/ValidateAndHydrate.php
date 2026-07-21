<?php

namespace Gtapps\LaravelAgentic\Kernel\Steps;

use Gtapps\LaravelAgentic\Kernel\ActionCall;
use Gtapps\LaravelAgentic\Schema\SchemaCompiler;

/**
 * @internal
 */
class ValidateAndHydrate
{
    public function __construct(protected SchemaCompiler $compiler) {}

    public function __invoke(ActionCall $call): void
    {
        if ($call->definition->inputClass === null) {
            return;
        }

        $call->input = $this->compiler->hydrate(
            $call->definition->inputClass,
            $call->rawArgs,
            $call->definition->inputSchema,
        );
    }
}
