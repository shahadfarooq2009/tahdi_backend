<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Textbook extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'textbooks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'proposed_structure' => 'array',
            'approved_structure' => 'array',
            'extraction_diagnostics' => 'array',
            'processing_stage_meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
