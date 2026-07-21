<?php

namespace Gtapps\LaravelAgentic\Kernel;

use Gtapps\LaravelAgentic\Enums\Mismatch;
use Gtapps\LaravelAgentic\Enums\Surface;

/**
 * Immutable, serializable snapshot of one action: attribute metadata plus
 * compiled schemas. This — not the live class — is what agentic:cache stores.
 *
 * @internal
 */
final class ActionDefinition
{
    /**
     * @param  Surface[]  $surfaces
     * @param  bool|class-string  $needsApproval
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $handler,
        public readonly bool $readOnly,
        public readonly bool|string $needsApproval,
        public readonly array $surfaces,
        public readonly ?string $inputClass,
        public readonly array $inputSchema,
        public readonly ?string $compactInputClass,
        public readonly ?array $compactInputSchema,
        public readonly ?string $outputSchema,
        public readonly Mismatch $outputMismatch,
        public readonly bool $audit,
        public readonly string $definitionHash,
    ) {}

    /**
     * The schema shown to models: compact when declared, else full.
     */
    public function agentSchema(): array
    {
        return $this->compactInputSchema ?? $this->inputSchema;
    }

    public function exposedTo(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    public static function hash(array $attributes): string
    {
        unset($attributes['definitionHash']);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'handler' => $this->handler,
            'readOnly' => $this->readOnly,
            'needsApproval' => $this->needsApproval,
            'surfaces' => Surface::values($this->surfaces),
            'inputClass' => $this->inputClass,
            'inputSchema' => $this->inputSchema,
            'compactInputClass' => $this->compactInputClass,
            'compactInputSchema' => $this->compactInputSchema,
            'outputSchema' => $this->outputSchema,
            'outputMismatch' => $this->outputMismatch->value,
            'audit' => $this->audit,
            'definitionHash' => $this->definitionHash,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'],
            handler: $data['handler'],
            readOnly: $data['readOnly'],
            needsApproval: $data['needsApproval'],
            surfaces: array_map(Surface::from(...), $data['surfaces']),
            inputClass: $data['inputClass'],
            inputSchema: $data['inputSchema'],
            compactInputClass: $data['compactInputClass'],
            compactInputSchema: $data['compactInputSchema'],
            outputSchema: $data['outputSchema'],
            outputMismatch: Mismatch::from($data['outputMismatch']),
            audit: $data['audit'],
            definitionHash: $data['definitionHash'],
        );
    }
}
