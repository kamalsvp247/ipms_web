<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccountSession>
 */
class AccountSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => null,
            'phone' => $this->faker->phoneNumber(),
            'signed_in_server_time' => $this->faker->iso8601(),
            'jwt_token' => $this->faker->sha256(),
            'otp_code' => $this->faker->numerify('######'),
            'otp_verified_server_time' => null,
            'slot_id' => null,
            'token_type' => 'Bearer',
            'expires_at' => 300,
            'user_id' => $this->faker->uuid(),
            'request_id' => $this->faker->uuid(),
            'roles' => null,
            'status_code' => 200,
            'message' => 'Success',
            'success_flag' => true,
            'server_time' => $this->faker->iso8601(),
        ];
    }
}
