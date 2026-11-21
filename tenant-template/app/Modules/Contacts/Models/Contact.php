<?php

namespace App\Modules\Contacts\Models;

use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, UsesUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'phone_number',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\ContactFactory::new();
    }
}
