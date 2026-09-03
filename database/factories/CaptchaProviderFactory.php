<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CaptchaProvider>
 */
class CaptchaProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(['capmonster', '2captcha', 'captchaai', 'capsolver', 'solvecaptcha']),
            'enabled' => false,
            'api_key' => fake()->uuid(),
            'solver_threads' => 1,
            'params' => null,
        ];
    }
}
