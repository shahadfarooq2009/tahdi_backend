<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolGame extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'school_games';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'game_number' => 'integer',
            'display_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SchoolUnit::class, 'unit_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'game_id');
    }
}
