<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TemplateSubmission extends Model
{
    use HasUlids;

    protected $fillable = ['template_id', 'organization_id', 'project_id', 'submitted_by', 'name', 'description', 'category', 'license', 'changelog', 'document', 'assets', 'sanitization', 'status', 'reviewed_by', 'review_reason', 'reviewed_at'];

    protected function casts(): array
    {
        return ['assets' => 'array', 'sanitization' => 'array', 'reviewed_at' => 'datetime'];
    }
}
