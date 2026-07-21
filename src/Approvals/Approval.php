<?php

namespace Gtapps\LaravelAgentic\Approvals;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasUlids;

    protected $table = 'agentic_approvals';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('agentic.approvals.connection');
    }

    protected function casts(): array
    {
        return [
            'args_redacted' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
