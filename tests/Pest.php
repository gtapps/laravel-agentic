<?php

use Gtapps\LaravelAgentic\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\User;

uses(TestCase::class)->in(__DIR__);

function approvalKey(string $text): string
{
    preg_match('/key ([0-9a-f]{64})/', $text, $matches);

    return $matches[1];
}

function useUsersTable(): void
{
    config(['auth.providers.users.model' => User::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
