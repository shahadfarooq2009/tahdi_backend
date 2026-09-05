<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'questions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'ai_generated' => 'boolean',
            'choice_options' => 'array',
            'points_value' => 'integer',
            'difficulty_level' => 'integer',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class, 'chapter_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function game()
    {
        return $this->belongsTo(SchoolGame::class, 'game_id');
    }

    public function submitter()
    {
        return $this->belongsTo(UserProfile::class, 'submitted_by');
    }
}
