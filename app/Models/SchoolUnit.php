<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolUnit extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'school_units';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_number' => 'integer',
            'display_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(SchoolGame::class, 'unit_id')->orderBy('display_order');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(SchoolCourse::class, 'course_id');
    }
}
