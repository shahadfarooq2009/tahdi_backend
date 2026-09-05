<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolCourse extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'school_courses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function parentSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'parent_subject_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(SchoolUnit::class, 'course_id')->orderBy('display_order');
    }
}
