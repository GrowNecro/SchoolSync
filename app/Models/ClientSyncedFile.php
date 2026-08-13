<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['client_computer_id', 'relative_path', 'storage_path', 'size', 'sha256', 'synced_at'])]
class ClientSyncedFile extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function computer()
    {
        return $this->belongsTo(ClientComputer::class, 'client_computer_id');
    }
}
