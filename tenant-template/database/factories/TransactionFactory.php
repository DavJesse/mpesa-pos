<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Modules\Branches\Models\Branch;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Support\Str;

class TransactionFactory extends Factory
{
    protected $model = \App\Modules\Mpesa\Models\Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'branch_id' => Branch::factory(),
            'contact_id' => Contact::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'payment_method' => 'mpesa',
            'status' => 'success',
            'mpesa_checkout_request_id' => 'ws_CO_' . Str::random(15),
            'mpesa_merchant_request_id' => Str::random(10) . '-1',
        ];
    }
}
