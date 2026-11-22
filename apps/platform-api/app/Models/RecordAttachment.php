<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RecordAttachment extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'record_id', 'drive_file_id', 'name', 'mime', 'size', 'status', 'uploaded_by', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'size' => 'integer'];
    }
}
