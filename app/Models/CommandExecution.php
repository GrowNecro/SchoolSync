<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['remote_command_id', 'client_computer_id', 'status', 'message', 'executed_at'])]
class CommandExecution extends Model
{
    protected function casts(): array
    {
        return ['executed_at' => 'datetime'];
    }

    public function command()
    {
        return $this->belongsTo(RemoteCommand::class, 'remote_command_id');
    }

    public function computer()
    {
        return $this->belongsTo(ClientComputer::class, 'client_computer_id');
    }
}
