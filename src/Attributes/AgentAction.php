<?php

namespace Gtapps\LaravelAgentic\Attributes;

use Attribute;
use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Enums\Surface;

#[Attribute(Attribute::TARGET_CLASS)]
class AgentAction
{
    /**
     * @param  string  $description  Written for the MODEL, not for humans.
     * @param  bool|class-string  $needsApproval  true, or a container-resolved invokable predicate.
     * @param  Surface[]  $surfaces
     * @param  class-string|null  $agentInputSchema  Compact DTO shown to models; the full schema still validates.
     * @param  class-string|null  $outputSchema  Mismatch::Fallback requires outputFallback(): mixed on the action class.
     * @param  ?bool  $audit  null = audit iff non-readOnly (default); true = always audit, even readOnly; false = never.
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $readOnly = false,
        public bool|string $needsApproval = false,
        public array $surfaces = [Surface::Mcp, Surface::AiTool, Surface::Http, Surface::Cli, Surface::Job],
        public ?string $agentInputSchema = null,
        public ?string $outputSchema = null,
        public Mismatch $outputMismatch = Mismatch::Warn,
        public ?bool $audit = null,
    ) {}
}
