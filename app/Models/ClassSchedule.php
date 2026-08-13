<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'schedule_day', 'start_time', 'end_time', 'project_id', 'browser', 'launcher',
    'shutdown_enabled', 'shutdown_warning', 'target_type', 'target_value', 'exam_enabled',
    'blocked_processes', 'enabled',
])]
class ClassSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'browser' => 'array',
            'launcher' => 'array',
            'target_value' => 'array',
            'shutdown_enabled' => 'boolean',
            'exam_enabled' => 'boolean',
            'blocked_processes' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
