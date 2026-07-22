<?php

use Gtapps\LaravelAgentic\Schema\SpatieDataCompiler;
use Gtapps\LaravelAgentic\Schema\UnsupportedSchemaType;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\AddressData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\ArrayConstraintsData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\ClosureData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\CollectionData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\ConstraintsData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\DefaultsData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\EnumArrayData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\EnumData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\GlobalMappedInputData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\MapArrayData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\MappedInputData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\NestedArrayData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\NestedData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\NullableData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\PaginatedFilterData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\PlainArrayData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\PureEnumData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\ScalarArrayData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\ScalarsData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\SelfRefData;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\Suit;
use Gtapps\LaravelAgentic\Tests\Fixtures\Schema\UnionData;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

const ADDRESS_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'street' => ['type' => 'string'],
        'city' => ['type' => 'string'],
    ],
    'additionalProperties' => false,
    'required' => ['street', 'city'],
];

dataset('schema fixtures', [
    'scalars' => [ScalarsData::class, [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'active' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
        'required' => ['name', 'count', 'ratio', 'active'],
    ]],
    'nullable' => [NullableData::class, [
        'type' => 'object',
        'properties' => [
            'note' => ['type' => ['string', 'null']],
            'suit' => ['type' => ['string', 'null'], 'enum' => ['hearts', 'spades', null], 'default' => null],
        ],
        'additionalProperties' => false,
    ]],
    'defaults' => [DefaultsData::class, [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'default' => 'anonymous'],
            'limit' => ['type' => 'integer', 'default' => 10],
            'suit' => ['type' => 'string', 'enum' => ['hearts', 'spades'], 'default' => 'hearts'],
        ],
        'additionalProperties' => false,
    ]],
    'enums' => [EnumData::class, [
        'type' => 'object',
        'properties' => [
            'suit' => ['type' => 'string', 'enum' => ['hearts', 'spades']],
            'priority' => ['type' => 'integer', 'enum' => [1, 2]],
        ],
        'additionalProperties' => false,
        'required' => ['suit', 'priority'],
    ]],
    'nested data object' => [NestedData::class, [
        'type' => 'object',
        'properties' => [
            'label' => ['type' => 'string'],
            'address' => ADDRESS_SCHEMA,
        ],
        'additionalProperties' => false,
        'required' => ['label', 'address'],
    ]],
    'array of DTOs' => [CollectionData::class, [
        'type' => 'object',
        'properties' => [
            'addresses' => ['type' => 'array', 'items' => ADDRESS_SCHEMA],
        ],
        'additionalProperties' => false,
        'required' => ['addresses'],
    ]],
    'scalar arrays' => [ScalarArrayData::class, [
        'type' => 'object',
        'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            'weights' => ['type' => 'array', 'items' => ['type' => 'number'], 'default' => []],
            'flags' => ['type' => 'array', 'items' => ['type' => 'boolean'], 'default' => []],
        ],
        'additionalProperties' => false,
    ]],
    'array size constraints' => [ArrayConstraintsData::class, [
        'type' => 'object',
        'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 2, 'default' => []],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3, 'default' => []],
        ],
        'additionalProperties' => false,
    ]],
    'constraints' => [ConstraintsData::class, [
        'type' => 'object',
        'properties' => [
            'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'title' => ['type' => 'string', 'maxLength' => 50],
            'code' => ['type' => 'string', 'pattern' => '^[A-Z]+$'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'slug' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 8],
        ],
        'additionalProperties' => false,
        'required' => ['quantity', 'title', 'code', 'email', 'slug'],
    ]],
    'paginated filter' => [PaginatedFilterData::class, [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15],
        ],
        'additionalProperties' => false,
        'required' => ['status'],
    ]],
    'input name mapper' => [MappedInputData::class, [
        'type' => 'object',
        'properties' => [
            'whitelabel_id' => ['type' => 'integer'],
            'display_name' => ['type' => 'string', 'default' => 'anon'],
        ],
        'additionalProperties' => false,
        'required' => ['whitelabel_id'],
    ]],
]);

it('compiles DTO fixtures to expected JSON Schema', function (string $dtoClass, array $expected) {
    $compiler = new SpatieDataCompiler;

    $expected = ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $expected;

    expect($compiler->compile($dtoClass))->toBe($expected);
})->with('schema fixtures');

it('is deterministic across repeated compiles', function () {
    $compiler = new SpatieDataCompiler;

    expect($compiler->compile(NestedData::class))->toBe($compiler->compile(NestedData::class));
});

dataset('unsupported fixtures', [
    'union type' => [UnionData::class, 'union'],
    'plain array without DataCollectionOf' => [PlainArrayData::class, 'DataCollectionOf'],
    'enum array (class-typed items unsupported in v1)' => [EnumArrayData::class, 'DataCollectionOf'],
    'nested scalar array (int[][] must not compile to a flat int[])' => [NestedArrayData::class, 'DataCollectionOf'],
    'string-keyed map (array<string, T> is a JSON object, not an array)' => [MapArrayData::class, 'DataCollectionOf'],
    'closure' => [ClosureData::class, 'unsupported'],
    'pure enum' => [PureEnumData::class, 'non-backed'],
    'non-Data class' => [Suit::class, 'not a subclass'],
    'self-referencing data class' => [SelfRefData::class, 'self-referencing'],
]);

it('throws UnsupportedSchemaType at compile time', function (string $dtoClass, string $messageFragment) {
    $compiler = new SpatieDataCompiler;

    expect(fn () => $compiler->compile($dtoClass))
        ->toThrow(UnsupportedSchemaType::class, $messageFragment);
})->with('unsupported fixtures');

it('hydrates scalars round-trip', function () {
    $dto = (new SpatieDataCompiler)->hydrate(ScalarsData::class, [
        'name' => 'refund', 'count' => 3, 'ratio' => 0.5, 'active' => true,
    ]);

    expect($dto)->toBeInstanceOf(ScalarsData::class)
        ->and($dto->name)->toBe('refund')
        ->and($dto->count)->toBe(3)
        ->and($dto->ratio)->toBe(0.5)
        ->and($dto->active)->toBeTrue();
});

it('hydrates nested data and collections', function () {
    $compiler = new SpatieDataCompiler;

    $nested = $compiler->hydrate(NestedData::class, [
        'label' => 'home',
        'address' => ['street' => '1 Main St', 'city' => 'Lisbon'],
    ]);

    expect($nested->address)->toBeInstanceOf(AddressData::class)
        ->and($nested->address->city)->toBe('Lisbon');

    $collection = $compiler->hydrate(CollectionData::class, [
        'addresses' => [
            ['street' => '1 Main St', 'city' => 'Lisbon'],
            ['street' => '2 High St', 'city' => 'Porto'],
        ],
    ]);

    expect($collection->addresses)->toHaveCount(2)
        ->and($collection->addresses[1])->toBeInstanceOf(AddressData::class)
        ->and($collection->addresses[1]->city)->toBe('Porto');
});

it('hydrates scalar array input end to end', function () {
    $dto = (new SpatieDataCompiler)->hydrate(ScalarArrayData::class, [
        'ids' => [1, 2, 3],
        'tags' => ['a', 'b'],
        'weights' => [1.5],
        'flags' => [true, false],
    ]);

    expect($dto->ids)->toBe([1, 2, 3])
        ->and($dto->tags)->toBe(['a', 'b'])
        ->and($dto->weights)->toBe([1.5])
        ->and($dto->flags)->toBe([true, false]);
});

it('enforces scalar array item types from the compiled schema', function () {
    // spatie infers only a bare `array` rule for a plain array property, so
    // without the schema-derived `ids.*` rules the advertised `items` would
    // never be enforced and junk would reach a handler typed against int[].
    $compiler = new SpatieDataCompiler;

    expect(fn () => $compiler->hydrate(
        ScalarArrayData::class,
        ['ids' => ['not-an-int', null, ['nested']]],
        $compiler->compile(ScalarArrayData::class),
    ))->toThrow(ValidationException::class);
});

it('coerces string-transport scalar array items to their declared type', function () {
    // CLI arguments and HTTP query strings deliver every element as a string,
    // and models routinely emit ["1","2"] for an integer array.
    $compiler = new SpatieDataCompiler;

    $dto = $compiler->hydrate(
        ScalarArrayData::class,
        ['ids' => ['1', '2'], 'weights' => ['1.5'], 'flags' => ['1', '0']],
        $compiler->compile(ScalarArrayData::class),
    );

    expect($dto->ids)->toBe([1, 2])
        ->and($dto->weights)->toBe([1.5])
        ->and($dto->flags)->toBe([true, false]);
});

it('leaves object-item collections to spatie rather than double-validating', function () {
    // #[DataCollectionOf] items already get per-element rules from spatie; the
    // schema walker must skip them, and passing the schema must not disturb
    // either the happy path or spatie's own nested error reporting.
    $compiler = new SpatieDataCompiler;
    $schema = $compiler->compile(CollectionData::class);

    $collection = $compiler->hydrate(CollectionData::class, [
        'addresses' => [['street' => 'Rua A', 'city' => 'Lisboa']],
    ], $schema);

    expect($collection->addresses[0]->city)->toBe('Lisboa');

    expect(fn () => $compiler->hydrate(CollectionData::class, [
        'addresses' => [['street' => 'Rua A']],
    ], $schema))->toThrow(ValidationException::class, 'addresses.0.city');
});

it('rejects an explicitly empty scalar array despite the schema allowing it', function () {
    // Known v1 gap, tracked separately: the schema marks a defaulted array
    // optional with `default: []`, so `{"ids": []}` is schema-valid — but
    // spatie's RequiredRuleInferrer never consults the default, and Laravel's
    // `required` rule treats [] as empty. Omitting the key is the only way to
    // mean "none"; `#[Present]` on the property is the per-DTO escape hatch.
    $compiler = new SpatieDataCompiler;

    expect(fn () => $compiler->hydrate(ScalarArrayData::class, ['ids' => []]))
        ->toThrow(ValidationException::class);

    expect($compiler->hydrate(ScalarArrayData::class, [])->ids)->toBe([]);
});

it('casts enums and applies defaults on hydration', function () {
    $compiler = new SpatieDataCompiler;

    $enums = $compiler->hydrate(EnumData::class, ['suit' => 'spades', 'priority' => 2]);

    expect($enums->suit)->toBe(Suit::Spades);

    $defaults = $compiler->hydrate(DefaultsData::class, []);

    expect($defaults->name)->toBe('anonymous')
        ->and($defaults->limit)->toBe(10)
        ->and($defaults->suit)->toBe(Suit::Hearts);
});

it('applies page/perPage defaults on a paginated input subclass', function () {
    $dto = (new SpatieDataCompiler)->hydrate(PaginatedFilterData::class, ['status' => 'open']);

    expect($dto->status)->toBe('open')
        ->and($dto->page)->toBe(1)
        ->and($dto->perPage)->toBe(15);
});

it('rejects out-of-range perPage on a paginated input subclass', function () {
    $compiler = new SpatieDataCompiler;

    expect(fn () => $compiler->hydrate(PaginatedFilterData::class, ['status' => 'open', 'perPage' => 0]))
        ->toThrow(ValidationException::class);

    expect(fn () => $compiler->hydrate(PaginatedFilterData::class, ['status' => 'open', 'perPage' => 101]))
        ->toThrow(ValidationException::class);
});

it('hydrates absent nullable fields to null', function () {
    $dto = (new SpatieDataCompiler)->hydrate(NullableData::class, []);

    expect($dto->note)->toBeNull()
        ->and($dto->suit)->toBeNull();
});

it('reports validation failures with field-keyed, model-usable errors', function () {
    try {
        (new SpatieDataCompiler)->hydrate(ScalarsData::class, ['count' => 'not-a-number']);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['name', 'count'])
            ->and($e->errors()['name'][0])->toContain('required')
            ->and($e->errors()['count'][0])->toContain('number');
    }
});

it('rejects constraint violations on hydration', function () {
    expect(fn () => (new SpatieDataCompiler)->hydrate(ConstraintsData::class, [
        'quantity' => 500,
        'title' => 'ok',
        'code' => 'ABC',
        'email' => 'not-an-email',
        'slug' => 'ok-slug',
    ]))->toThrow(ValidationException::class);
});

it('hydrates the schema-advertised mapped keys and rejects raw PHP names', function () {
    $compiler = new SpatieDataCompiler;

    $dto = $compiler->hydrate(MappedInputData::class, ['whitelabel_id' => 7]);

    expect($dto->whitelabelId)->toBe(7)
        ->and($dto->displayName)->toBe('anon');

    expect(fn () => $compiler->hydrate(MappedInputData::class, ['whitelabelId' => 7]))
        ->toThrow(ValidationException::class);
});

it('maps input names via the global data.name_mapping_strategy.input config', function () {
    config(['data.name_mapping_strategy.input' => SnakeCaseMapper::class]);

    $compiler = new SpatieDataCompiler;

    expect($compiler->compile(GlobalMappedInputData::class)['properties'])
        ->toHaveKey('whitelabel_id');

    expect($compiler->hydrate(GlobalMappedInputData::class, ['whitelabel_id' => 7])->whitelabelId)
        ->toBe(7);
});
