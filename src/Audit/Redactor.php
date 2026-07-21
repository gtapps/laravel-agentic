<?php

namespace Gtapps\LaravelAgentic\Audit;

use Illuminate\Contracts\Config\Repository;

/**
 * One redaction config, two sinks: applied to BOTH audit rows and
 * approval payloads. Globs match dot-paths (e.g. '*.password' matches
 * 'card.password'; 'password' matches the top-level field).
 */
class Redactor
{
    public function __construct(protected Repository $config) {}

    public function redact(array $args): array
    {
        $globs = $this->config->get('agentic.redact', []);

        return $globs === [] ? $args : $this->walk($args, '', $globs);
    }

    protected function walk(array $values, string $prefix, array $globs): array
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if ($this->matches($path, $globs)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->walk($value, $path, $globs);
            }
        }

        return $values;
    }

    protected function matches(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path)) {
                return true;
            }
        }

        return false;
    }
}
