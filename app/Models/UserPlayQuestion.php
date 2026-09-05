<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlayQuestion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points_value' => 'integer',
            'sort_order' => 'integer',
            'is_approved' => 'boolean',
            'ai_generated' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function playSet(): BelongsTo
    {
        return $this->belongsTo(UserPlaySet::class, 'play_set_id');
    }
}
