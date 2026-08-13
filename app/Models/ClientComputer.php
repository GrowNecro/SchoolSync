<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['installation_id', 'computer_name', 'group_name', 'client_token_hash', 'approved', 'approved_at', 'version', 'inventory', 'ip_address', 'last_seen_at', 'last_interactive_at'])]
class ClientComputer extends Model
{
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_interactive_at' => 'datetime',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
            'inventory' => 'array',
        ];
    }

    public function syncedFiles()
    {
        return $this->hasMany(ClientSyncedFile::class);
    }

    public function commandExecutions()
    {
        return $this->hasMany(CommandExecution::class);
    }
}
