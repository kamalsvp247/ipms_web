<?php

namespace App\Actions\Fortify;

use App\Services\Security\TurnstileVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EnsureCaptchaIsValid
{
    public function __construct(private TurnstileVerifier $verifier)
    {
    }

    /**
     * Handle the incoming request as part of the Fortify login pipeline.
     */
    public function handle(Request $request, callable $next): mixed
    {
        if (! $this->verifier->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => 'Captcha verification failed. Please try again.',
            ]);
        }

        return $next($request);
    }
}
