<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentLink>
 */
class PaymentLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway_page_url' => $this->faker->url(),
            'redirect_gateway_url' => $this->faker->url(),
            'status_code' => 200,
            'message' => $this->faker->sentence(),
            'success_flag' => true,
            'is_clicked' => false,
            'server_time' => $this->faker->iso8601(),
            'payload' => [],
        ];
    }
}
