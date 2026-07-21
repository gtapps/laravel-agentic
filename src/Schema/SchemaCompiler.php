<?php

namespace Gtapps\LaravelAgentic\Schema;

interface SchemaCompiler
{
    /**
     * DTO class-string → JSON Schema (array form, draft 2020-12). Cacheable, deterministic.
     *
     * @throws UnsupportedSchemaType for types outside the supported v1 set.
     */
    public function compile(string $dtoClass): array;

    /**
     * Validated raw args → hydrated DTO. Throws ValidationException with field errors.
     */
    public function hydrate(string $dtoClass, array $args): object;
}
