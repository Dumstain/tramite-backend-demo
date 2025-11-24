<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcedureBlueprint extends Model
{
    use HasUuids;

    protected $table = 'blueprints';

    protected $fillable = ['name', 'description', 'schema', 'is_active'];

    protected $casts = [
        'schema' => 'array', // CRÍTICO: Convierte JSON DB <-> Array PHP
        'is_active' => 'boolean',
    ];
}
