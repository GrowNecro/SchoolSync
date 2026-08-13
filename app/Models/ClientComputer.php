<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['installation_id', 'computer_name', 'version', 'ip_address', 'last_seen_at', 'last_interactive_at'])]
class ClientComputer extends Model
{
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_interactive_at' => 'datetime',
        ];
    }

    public function syncedFiles()
    {
        return $this->hasMany(ClientSyncedFile::class);
    }
}
