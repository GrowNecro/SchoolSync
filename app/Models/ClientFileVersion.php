<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['client_synced_file_id', 'storage_path', 'size', 'sha256'])]
class ClientFileVersion extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function syncedFile()
    {
        return $this->belongsTo(ClientSyncedFile::class, 'client_synced_file_id');
    }
}
