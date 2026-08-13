<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['filename', 'path', 'size', 'sha256', 'extract'])]
class Project extends Model
{
    protected function casts(): array
    {
        return [
            'extract' => 'boolean',
            'size' => 'integer',
        ];
    }

    public function settings()
    {
        return $this->hasMany(Setting::class);
    }
}
