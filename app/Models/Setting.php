<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'schedule_day', 'start_time', 'end_time', 'project_id', 'browser',
    'launcher', 'shutdown_enabled', 'shutdown_warning', 'shutdown_excluded_computers',
])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'browser' => 'array',
            'launcher' => 'array',
            'shutdown_enabled' => 'boolean',
            'shutdown_excluded_computers' => 'array',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public static function defaults(): array
    {
        return [
            'schedule_day' => 'Friday',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'browser' => ['https://classroom.google.com'],
            'launcher' => ['edge', 'roblox'],
            'shutdown_enabled' => false,
            'shutdown_warning' => 10,
            'shutdown_excluded_computers' => [],
        ];
    }
}
