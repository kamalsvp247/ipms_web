<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Self-registration always mints an agent under the manager or sub manager identified by
     * the referral code, which is how we know whose subtree a Request Access signup belongs
     * to. Both owner tiers carry a code, so an agent can sign up under either.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! app(TurnstileVerifier::class)->verify($input['cf-turnstile-response'] ?? null, request()->ip())) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'Captcha verification failed. Please try again.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'phone' => $this->phoneRules(),
            'password' => $this->passwordRules(),
            'referral_code' => [
                'required',
                'string',
                Rule::exists(User::class, 'referral_code')->whereIn('role', User::OWNER_ROLES),
            ],
        ], [
            'referral_code.exists' => 'Invalid referral code — check with your manager.',
        ])->validate();

        $manager = User::where('referral_code', $input['referral_code'])
            ->whereIn('role', User::OWNER_ROLES)
            ->firstOrFail();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'password' => $input['password'],
            'plain_password' => $input['password'],
            'role' => User::ROLE_AGENT,
            'parent_id' => $manager->id,
            'is_approved' => false,
        ]);
    }
}
