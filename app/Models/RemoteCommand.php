<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'payload', 'target_type', 'target_value', 'expires_at'])]
class RemoteCommand extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'target_value' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function executions()
    {
        return $this->hasMany(CommandExecution::class);
    }
}
