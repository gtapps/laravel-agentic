<?php

namespace Gtapps\LaravelAgentic\Approvals;

use Gtapps\LaravelAgentic\Kernel\ActionDefinition;
use InvalidArgumentException;

/**
 * Approval-key canonicalization:
 * validated raw args with top-level schema defaults applied, assoc arrays
 * recursively ksorted, list order preserved, non-UTF8 rejected,
 * key = sha256(action_name . "\0" . canonical_json).
 *
 * @internal
 */
class Canonicalizer
{
    public static function key(ActionDefinition $definition, array $rawArgs): string
    {
        $canonical = self::canonicalize(self::withDefaults($definition, $rawArgs));

        try {
            $json = json_encode(
                $canonical,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Action args are not canonicalizable: '.$e->getMessage(), 0, $e);
        }

        return hash('sha256', $definition->name."\0".$json);
    }

    /**
     * Defaults come from the compiled schema so an explicit-default call and
     * an omitted-field call hash identically.
     */
    public static function withDefaults(ActionDefinition $definition, array $rawArgs): array
    {
        foreach ($definition->inputSchema['properties'] ?? [] as $field => $schema) {
            if (! array_key_exists($field, $rawArgs) && array_key_exists('default', $schema)) {
                $rawArgs[$field] = $schema['default'];
            }
        }

        return $rawArgs;
    }

    protected static function canonicalize(array $args): array
    {
        if (! array_is_list($args)) {
            ksort($args);
        }

        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $args[$key] = self::canonicalize($value);
            }
        }

        return $args;
    }
}
