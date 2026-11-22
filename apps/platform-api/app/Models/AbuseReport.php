<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AbuseReport extends Model
{
    use HasUlids;

    protected $fillable = ['url', 'host', 'organization_id', 'category', 'details', 'reporter_email', 'reporter_ip_hash', 'status', 'handled_by', 'resolution'];
}
