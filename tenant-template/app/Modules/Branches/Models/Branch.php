<?php

namespace App\Modules\Branches\Models;

use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Auth\Models\User;
use App\Modules\Mpesa\Models\Transaction;

class Branch extends Model
{
    use HasFactory, UsesUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'mpesa_shortcode_override_nullable',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
