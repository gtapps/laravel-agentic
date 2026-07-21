<?php

namespace Gtapps\LaravelAgentic\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ActionLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'agentic_action_log';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('agentic.audit.connection');
    }

    protected function casts(): array
    {
        return [
            'args' => 'array',
        ];
    }
}
