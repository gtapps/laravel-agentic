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
     *
     * The compiled schema is optional so the signature stays backwards
     * compatible, but callers should pass it: constraints the DTO's own
     * validation rules can't express (scalar array item types) are enforced
     * from it, and elements are coerced to the types it declares.
     */
    public function hydrate(string $dtoClass, array $args, array $schema = []): object;
}
