<?php

namespace Gtapps\LaravelAgentic\Schema;

use BackedEnum;
use Illuminate\Support\Facades\Validator;
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
use Spatie\LaravelData\Support\DataConfig;

/**
 * @internal v1 SchemaCompiler implementation on spatie/laravel-data.
 */
class SpatieDataCompiler implements SchemaCompiler
{
    /**
     * Laravel rule per JSON Schema scalar type, for array elements.
     */
    protected const ITEM_RULES = [
        'integer' => 'integer',
        'number' => 'numeric',
        'string' => 'string',
        'boolean' => 'boolean',
    ];

    /** @var array<class-string, true> guards against infinite recursion on cyclic Data references */
    protected array $compiling = [];

    public function compile(string $dtoClass): array
    {
        $this->compiling = [];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema']
            + $this->compileObject($dtoClass);
    }

    public function hydrate(string $dtoClass, array $args, array $schema = []): object
    {
        $itemTypes = $this->scalarArrayItemTypes($schema);

        if ($itemTypes !== []) {
            $args = $this->castScalarArrayItems($args, $itemTypes);

            Validator::make($args, array_map(
                fn (string $type) => self::ITEM_RULES[$type],
                $itemTypes
            ))->validate();
        }

        return $dtoClass::validateAndCreate($args);
    }

    /**
     * Compiled schema → dot path (`ids.*`) → JSON Schema scalar type, for every
     * scalar array in the tree. spatie infers only a bare `array` rule for a
     * plain array property, so without this the compiled `items` would be
     * advertised to models and never enforced. Object items are skipped —
     * #[DataCollectionOf] collections already get per-element rules from spatie.
     *
     * @return array<string, string>
     */
    protected function scalarArrayItemTypes(array $schema, string $prefix = ''): array
    {
        $types = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $path = $prefix.$name;
            $propertyTypes = (array) ($property['type'] ?? []);

            if (in_array('object', $propertyTypes, true)) {
                $types += $this->scalarArrayItemTypes($property, $path.'.');

                continue;
            }

            if (! in_array('array', $propertyTypes, true) || ! isset($property['items'])) {
                continue;
            }

            $itemType = ((array) ($property['items']['type'] ?? []))[0] ?? null;

            if ($itemType === 'object') {
                $types += $this->scalarArrayItemTypes($property['items'], $path.'.*.');
            } elseif (isset(self::ITEM_RULES[$itemType])) {
                $types[$path.'.*'] = $itemType;
            }
        }

        return $types;
    }

    /**
     * Transports differ in fidelity — a CLI argument or an HTTP query string
     * delivers every element as a string — so `["1","2"]` must still reach an
     * int[] handler as ints. Only lossless coercions are applied; anything else
     * passes through untouched for the validator to reject.
     *
     * @param  array<string, string>  $itemTypes
     */
    protected function castScalarArrayItems(array $args, array $itemTypes): array
    {
        foreach ($itemTypes as $path => $type) {
            $args = $this->castAtPath($args, explode('.', $path), $type);
        }

        return $args;
    }

    protected function castAtPath(array $target, array $segments, string $type): array
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            foreach ($target as $key => $value) {
                if ($segments === []) {
                    $target[$key] = $this->castScalar($value, $type);
                } elseif (is_array($value)) {
                    $target[$key] = $this->castAtPath($value, $segments, $type);
                }
            }

            return $target;
        }

        // A missing key, or one holding the wrong shape entirely, is left for
        // spatie's own `array` rule to report.
        if (is_array($target[$segment] ?? null)) {
            $target[$segment] = $this->castAtPath($target[$segment], $segments, $type);
        }

        return $target;
    }

    protected function castScalar(mixed $value, string $type): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        return match ($type) {
            'integer' => is_numeric($value) && (int) $value == $value ? (int) $value : $value,
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
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
        $mappedNames = $this->inputMappedNames($dtoClass);

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $schema = $this->compileProperty($dtoClass, $property);
            $key = $mappedNames[$property->getName()];

            $hasDefault = array_key_exists($property->getName(), $defaults)
                || $property->hasDefaultValue();

            if ($hasDefault) {
                $default = $defaults[$property->getName()] ?? $property->getDefaultValue();
                $schema['default'] = $default instanceof BackedEnum ? $default->value : $default;
            } elseif (! $property->getType()?->allowsNull()) {
                $required[] = $key;
            }

            $properties[$key] = $schema;
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
                'string', 'int', 'float', 'bool' => $this->scalarTypeSchema($name),
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

        if ($of !== null) {
            return [
                'type' => 'array',
                'items' => $this->compileObject($of->newInstance()->class),
            ];
        }

        $items = $this->scalarItemsFromDocblock($property);

        if ($items !== null) {
            return ['type' => 'array', 'items' => $items];
        }

        throw UnsupportedSchemaType::forProperty(
            $dtoClass, $property->getName(),
            'array property requires #[DataCollectionOf] (object items) or a @var T[] / list<T> / '
            .'array<T> / array<K, T> docblock with T = int|string|float|bool in v1'
        );
    }

    /**
     * Scalar array element type inferred from the property docblock, since
     * #[DataCollectionOf] only supports Data-object items. Limited to builtin
     * scalars in v1 — a class-typed T (e.g. a backed enum) would need resolving
     * a possibly-short name against the file's use-statements, which formatters
     * like Pint's fully_qualified_strict_types rule can rewrite unpredictably;
     * out of scope until that's resolved properly.
     */
    protected function scalarItemsFromDocblock(ReflectionProperty $property): ?array
    {
        $doc = $property->getDocComment();

        if ($doc === false) {
            return null;
        }

        $scalar = 'int|string|float|bool';

        // `T[]`; the trailing lookahead rejects nested `T[][]`, which would
        // otherwise match its prefix and compile to a flat array of scalars.
        if (preg_match('/@var\s+('.$scalar.')\[\](?!\[)/', $doc, $matches)) {
            return $this->scalarTypeSchema($matches[1]);
        }

        // `list<T>` / `array<T>` / `array<int, T>` — the generic style this repo
        // uses itself. A non-scalar T falls through to the unsupported error, as
        // does a non-int key: `array<string, T>` is a JSON *object*, not an
        // array, so compiling it to `type: array` would advertise the wrong shape.
        if (preg_match('/@var\s+(?:list|array)<\s*(?:int\s*,\s*)?('.$scalar.')\s*>/', $doc, $matches)) {
            return $this->scalarTypeSchema($matches[1]);
        }

        return null;
    }

    /**
     * JSON Schema type for a builtin PHP scalar name, shared by direct property
     * types (typeSchema) and array docblock element types (scalarItemsFromDocblock)
     * so the two mappings can't drift apart.
     */
    protected function scalarTypeSchema(string $name): array
    {
        return match ($name) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
        };
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
     * (Laravel min/max mean length for strings, item count for arrays, magnitude
     * for numbers).
     */
    protected function applyConstraints(array $schema, ReflectionProperty $property, ReflectionNamedType $type): array
    {
        $sizeKeys = match ($type->getName()) {
            'string' => ['minLength', 'maxLength'],
            'array' => ['minItems', 'maxItems'],
            default => ['minimum', 'maximum'],
        };

        foreach ($property->getAttributes() as $attribute) {
            $constraint = match ($attribute->getName()) {
                Min::class => [$sizeKeys[0] => $attribute->newInstance()->parameters()[0]],
                Max::class => [$sizeKeys[1] => $attribute->newInstance()->parameters()[0]],
                Between::class => $this->betweenConstraint($attribute->newInstance()->parameters(), $sizeKeys),
                Regex::class => ['pattern' => $this->stripRegexDelimiters($attribute->newInstance()->parameters()[0])],
                Email::class => ['format' => 'email'],
                default => [],
            };

            $schema += $constraint;
        }

        return $schema;
    }

    /**
     * @param  array{string, string}  $sizeKeys
     */
    protected function betweenConstraint(array $parameters, array $sizeKeys): array
    {
        [$min, $max] = $parameters;

        return [$sizeKeys[0] => $min, $sizeKeys[1] => $max];
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

    /**
     * Wire-facing input name per raw PHP property name, honoring spatie's input
     * name mappers (attribute or the global data.name_mapping_strategy.input) so
     * advertised schema keys match what validateAndCreate() accepts. Raw name
     * when no mapper is set — a no-op for mapper-less DTOs.
     *
     * @return array<string, string>
     */
    protected function inputMappedNames(string $dtoClass): array
    {
        $names = [];

        foreach (app(DataConfig::class)->getDataClass($dtoClass)->properties as $property) {
            $names[$property->name] = $property->inputMappedName ?? $property->name;
        }

        return $names;
    }
}
