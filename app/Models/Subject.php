<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'subjects';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'is_high_school_parent' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'stage_icons' => 'array',
        ];
    }

    public function subjectGrades()
    {
        return $this->hasMany(SubjectGrade::class, 'subject_id');
    }

    /**
     * @deprecated Use subjectGrades() — subjects table has a legacy `grades` column.
     */
    public function grades()
    {
        return $this->subjectGrades();
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function schoolCourses()
    {
        return $this->hasMany(SchoolCourse::class, 'parent_subject_id')->orderBy('display_order');
    }
}
