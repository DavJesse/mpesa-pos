<?php

namespace App\Modules\Contacts\Models;

use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Scopes\BranchScope;
use App\Modules\Branches\Models\Branch;

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

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::addGlobalScope(new BranchScope);
    }
}
