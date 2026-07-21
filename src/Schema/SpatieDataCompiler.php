<?php

namespace Gtapps\LaravelAgentic\Schema;

use BackedEnum;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * @internal v1 SchemaCompiler implementation on spatie/laravel-data.
 */
class SpatieDataCompiler implements SchemaCompiler
{
    /** @var array<class-string, true> guards against infinite recursion on cyclic Data references */
    protected array $compiling = [];

    public function compile(string $dtoClass): array
    {
        $this->compiling = [];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema']
            + $this->compileObject($dtoClass);
    }

    public function hydrate(string $dtoClass, array $args): object
    {
        return $dtoClass::validateAndCreate($args);
    }

    protected function compileObject(string $dtoClass): array
    {
        if (! is_subclass_of($dtoClass, Data::class)) {
            throw UnsupportedSchemaType::forClass($dtoClass, 'not a subclass of '.Data::class);
        }

        if (isset($this->compiling[$dtoClass])) {
            throw UnsupportedSchemaType::forClass(
                $dtoClass, 'self-referencing (directly or via a cycle) Data classes are unsupported in v1'
            );
        }

        $this->compiling[$dtoClass] = true;

        $reflection = new ReflectionClass($dtoClass);
        $defaults = $this->constructorDefaults($reflection);

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $schema = $this->compileProperty($dtoClass, $property);

            $hasDefault = array_key_exists($property->getName(), $defaults)
                || $property->hasDefaultValue();

            if ($hasDefault) {
                $default = $defaults[$property->getName()] ?? $property->getDefaultValue();
                $schema['default'] = $default instanceof BackedEnum ? $default->value : $default;
            } elseif (! $property->getType()?->allowsNull()) {
                $required[] = $property->getName();
            }

            $properties[$property->getName()] = $schema;
        }

        unset($this->compiling[$dtoClass]);

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    protected function compileProperty(string $dtoClass, ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type === null) {
            throw UnsupportedSchemaType::forProperty($dtoClass, $property->getName(), 'untyped property');
        }

        if (! $type instanceof ReflectionNamedType) {
            throw UnsupportedSchemaType::forProperty(
                $dtoClass,
                $property->getName(),
                'union or intersection types beyond nullable are unsupported in v1'
            );
        }

        $schema = $this->typeSchema($dtoClass, $property, $type);

        if ($type->allowsNull()) {
            $schema = $this->nullable($schema);
        }

        return $this->applyConstraints($schema, $property, $type);
    }

    protected function typeSchema(string $dtoClass, ReflectionProperty $property, ReflectionNamedType $type): array
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'string' => ['type' => 'string'],
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'array' => $this->arraySchema($dtoClass, $property),
                default => throw UnsupportedSchemaType::forProperty(
                    $dtoClass, $property->getName(), "builtin type '{$name}' is unsupported in v1"
                ),
            };
        }

        if (enum_exists($name)) {
            return $this->enumSchema($dtoClass, $property, $name);
        }

        if (is_subclass_of($name, Data::class)) {
            return $this->compileObject($name);
        }

        throw UnsupportedSchemaType::forProperty(
            $dtoClass, $property->getName(), "object type '{$name}' is unsupported in v1 (only Data subclasses and backed enums)"
        );
    }

    protected function arraySchema(string $dtoClass, ReflectionProperty $property): array
    {
        $of = $property->getAttributes(DataCollectionOf::class)[0] ?? null;

        if ($of === null) {
            throw UnsupportedSchemaType::forProperty(
                $dtoClass, $property->getName(), 'array property requires #[DataCollectionOf] in v1'
            );
        }

        return [
            'type' => 'array',
            'items' => $this->compileObject($of->newInstance()->class),
        ];
    }

    protected function enumSchema(string $dtoClass, ReflectionProperty $property, string $enumClass): array
    {
        $backing = (new ReflectionEnum($enumClass))->getBackingType()?->getName();

        if ($backing === null) {
            throw UnsupportedSchemaType::forProperty(
                $dtoClass, $property->getName(), "pure (non-backed) enum '{$enumClass}' is unsupported in v1"
            );
        }

        return [
            'type' => $backing === 'int' ? 'integer' : 'string',
            'enum' => array_map(fn (BackedEnum $case) => $case->value, $enumClass::cases()),
        ];
    }

    protected function nullable(array $schema): array
    {
        $schema['type'] = [$schema['type'], 'null'];

        if (isset($schema['enum'])) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    /**
     * Map spatie validation attributes to JSON Schema constraints, sized by base type
     * (Laravel min/max mean length for strings, magnitude for numbers).
     */
    protected function applyConstraints(array $schema, ReflectionProperty $property, ReflectionNamedType $type): array
    {
        $isString = $type->getName() === 'string';

        foreach ($property->getAttributes() as $attribute) {
            $constraint = match ($attribute->getName()) {
                Min::class => [$isString ? 'minLength' : 'minimum' => $attribute->newInstance()->parameters()[0]],
                Max::class => [$isString ? 'maxLength' : 'maximum' => $attribute->newInstance()->parameters()[0]],
                Between::class => $this->betweenConstraint($attribute->newInstance()->parameters(), $isString),
                Regex::class => ['pattern' => $this->stripRegexDelimiters($attribute->newInstance()->parameters()[0])],
                Email::class => ['format' => 'email'],
                default => [],
            };

            $schema += $constraint;
        }

        return $schema;
    }

    protected function betweenConstraint(array $parameters, bool $isString): array
    {
        [$min, $max] = $parameters;

        return $isString
            ? ['minLength' => $min, 'maxLength' => $max]
            : ['minimum' => $min, 'maximum' => $max];
    }

    protected function stripRegexDelimiters(string $pattern): string
    {
        if (strlen($pattern) >= 2 && $pattern[0] === $pattern[-1] && ! ctype_alnum($pattern[0])) {
            return substr($pattern, 1, -1);
        }

        return $pattern;
    }

    /**
     * @return array<string, mixed> promoted-constructor-parameter name → default value
     */
    protected function constructorDefaults(ReflectionClass $reflection): array
    {
        $defaults = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        return $defaults;
    }
}
