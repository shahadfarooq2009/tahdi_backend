<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TextbookProcessingJob extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'textbook_processing_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
