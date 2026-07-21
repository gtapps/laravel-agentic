<?php

use Gtapps\LaravelAgentic\AgenticServiceProvider;

it('boots the service provider', function () {
    expect($this->app->getProviders(AgenticServiceProvider::class))->toHaveCount(1);
});
