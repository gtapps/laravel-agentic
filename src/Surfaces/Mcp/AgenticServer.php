<?php

namespace Gtapps\LaravelAgentic\Surfaces\Mcp;

use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Kernel\Registry;
use Laravel\Mcp\Server;

class AgenticServer extends Server
{
    protected string $name = 'agentic';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'TEXT'
        Tools on this server are governed actions: they validate input, check
        authorization, and may require human approval. When a call returns
        "Approval required ... key {key}", a human must approve it; then retry
        the exact same call with identical arguments — it will execute once.
        TEXT;

    protected function boot(): void
    {
        $registry = app(Registry::class);

        foreach ($registry->definitions() as $definition) {
            if ($definition->exposedTo(Surface::Mcp)) {
                $this->tools[] = new ActionTool($definition);
            }
        }
    }
}
