<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BranchFactory extends Factory
{
    protected $model = \App\Modules\Branches\Models\Branch::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => 'Branch ' . Str::random(5),
            'location' => $this->faker->city(),
            'mpesa_shortcode_override_nullable' => null,
        ];
    }
}
