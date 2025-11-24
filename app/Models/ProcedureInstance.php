<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcedureInstance extends Model
{
    use HasUuids;

    protected $fillable = [
        'blueprint_id',
        'user_identifier',
        'status',
        'current_step_id',
        'state_store'
    ];

    protected $casts = [
        'state_store' => 'array'
    ];

    public function blueprint()
    {
        return $this->belongsTo(ProcedureBlueprint::class, 'blueprint_id');
    }
}
