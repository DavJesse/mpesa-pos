<?php

namespace App\Modules\System\Models;

use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemSetting extends Model
{
    use HasFactory, UsesUuid, SoftDeletes;

    protected $fillable = [
        'key',
        'payload',
    ];

    protected $casts = [
        'payload' => 'json',
    ];
}
