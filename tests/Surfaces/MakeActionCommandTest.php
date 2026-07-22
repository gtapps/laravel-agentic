<?php

use Gtapps\LaravelAgentic\Kernel\Registry;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(app_path('Actions/GeneratorTest'));
    app(Registry::class)->clearCache();
});

it('scaffolds an action and its input DTO', function () {
    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo'])
        ->assertSuccessful();

    $action = File::get(app_path('Actions/GeneratorTest/Foo.php'));
    expect($action)
        ->toContain('namespace App\Actions\GeneratorTest;')
        ->toContain('class Foo')
        ->toContain("name: 'foo',")
        ->toContain('function handle(FooInput $input): mixed');

    $input = File::get(app_path('Actions/GeneratorTest/FooInput.php'));
    expect($input)
        ->toContain('namespace App\Actions\GeneratorTest;')
        ->toContain('class FooInput extends Data');
});

it('nests sub-namespaced action names correctly', function () {
    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Nested/Foo'])
        ->assertSuccessful();

    expect(File::get(app_path('Actions/GeneratorTest/Nested/Foo.php')))
        ->toContain('namespace App\Actions\GeneratorTest\Nested;');

    File::deleteDirectory(app_path('Actions/GeneratorTest/Nested'));
});

it('refuses to overwrite without --force and overwrites with it', function () {
    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo'])->assertSuccessful();

    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo'])->assertFailed();

    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo', '--force' => true])
        ->assertSuccessful();
});

it('scaffolds a paginated listing action', function () {
    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo', '--paginated' => true])
        ->assertSuccessful();

    expect(File::get(app_path('Actions/GeneratorTest/FooInput.php')))
        ->toContain('extends PaginatedInput');

    expect(File::get(app_path('Actions/GeneratorTest/Foo.php')))
        ->toContain('LengthAwarePaginator');
});

it('generates a discoverable action', function () {
    $this->artisan('agentic:make-action', ['name' => 'GeneratorTest/Foo'])->assertSuccessful();

    config(['agentic.discovery.paths' => [app_path('Actions/GeneratorTest')]]);

    expect(app(Registry::class)->find('foo'))->not->toBeNull();
});
