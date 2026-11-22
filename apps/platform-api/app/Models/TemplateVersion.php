<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TemplateVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['template_id', 'version', 'document', 'content_hash'];
}
