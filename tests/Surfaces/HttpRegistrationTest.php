<?php

use Illuminate\Support\Facades\Route;

it('registers no HTTP route on a default boot', function () {
    expect(Route::has('agentic.actions.run'))->toBeFalse()
        ->and(Route::has('agentic.actions.show'))->toBeFalse();
});
