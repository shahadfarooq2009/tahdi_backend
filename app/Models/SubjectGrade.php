<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubjectGrade extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'subject_grades';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }
}
