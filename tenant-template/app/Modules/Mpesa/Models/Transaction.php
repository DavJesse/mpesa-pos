<?php

namespace App\Modules\Mpesa\Models;

use App\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Scopes\BranchScope;
use App\Modules\Branches\Models\Branch;
use App\Modules\Auth\Models\User;
use App\Modules\Contacts\Models\Contact;

class Transaction extends Model
{
    use HasFactory, UsesUuid, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'contact_id',
        'amount',
        'payment_method',
        'status',
        'mpesa_checkout_request_id',
        'mpesa_merchant_request_id',
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
