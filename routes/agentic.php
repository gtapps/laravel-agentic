<?php

use Gtapps\LaravelAgentic\Surfaces\Http\ActionController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('agentic.http.prefix', 'agentic'),
    'middleware' => config('agentic.http.middleware', ['api']),
], function () {
    Route::post('/actions/{name}', ActionController::class)->name('agentic.actions.run');
    Route::get('/actions/{name}', ActionController::class)->name('agentic.actions.show');
});
