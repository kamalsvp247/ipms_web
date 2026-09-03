<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaTokenType;
use App\Http\Controllers\Controller;
use App\Models\CaptchaProvider;
use App\Models\CaptchaToken;
use App\Models\Setting;
use App\Services\Captcha\CaptchaEncryptionService;
use App\Support\CaptchaDeliveryGate;
use App\Support\CaptchaPoolExpiry;
use Illuminate\Http\Request;

class CaptchaTokenController extends Controller
{
    public function index(Request $request)
    {
        CaptchaToken::where('created_at', '<=', CaptchaPoolExpiry::cutoff())->delete();

        $search = $request->query('search');
        $query = CaptchaToken::with('provider');

        if ($search) {
            $query->where('token', 'like', "%{$search}%")
                ->orWhereHas('provider', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
        }

        return $query->latest()->paginate($request->query('per_page', 10));
    }

    /**
     * Lightweight endpoint: purge expired tokens, return total count and per-type breakdown.
     * Used by captcha-automation workers to check limit without loading full list.
     */
    public function count(): \Illuminate\Http\JsonResponse
    {
        CaptchaToken::where('created_at', '<=', CaptchaPoolExpiry::cutoff())->delete();

        $byType = CaptchaToken::selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->toArray();

        return response()->json([
            'total' => array_sum($byType),
            'by_type' => [
                'turnstile' => $byType['turnstile'] ?? 0,
                'turnstile_encrypted' => $byType['turnstile_encrypted'] ?? 0,
                'recaptcha' => $byType['recaptcha'] ?? 0,
            ],
        ]);
    }

    /**
     * Store a solved token from the browser-side captcha automation.
     * Applies ft-function transformation for turnstile_encrypted type.
     */
    public function storeSolved(Request $request, CaptchaEncryptionService $encryptor): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'provider_id' => 'required|exists:captcha_providers,id',
            'type' => 'required|in:turnstile,turnstile_encrypted,recaptcha',
            'raw_token' => 'required|string',
        ]);

        $rawToken = $data['raw_token'];
        $type = CaptchaTokenType::from($data['type']);

        if ($type === CaptchaTokenType::Turnstile) {
            $rawToken = $encryptor->encryptLogin($rawToken);
            if ($rawToken === null) {
                return response()->json(['error' => 'Login captcha encryption unavailable (sidecar unreachable or meta not ready).'], 500);
            }
        } elseif ($type === CaptchaTokenType::TurnstileEncrypted) {
            $rawToken = $encryptor->encryptReserve($rawToken);
            if ($rawToken === null) {
                return response()->json(['error' => 'Reserve captcha encryption unavailable (sidecar unreachable or meta not ready).'], 500);
            }
        }

        $token = CaptchaToken::create([
            'provider_id' => $data['provider_id'],
            'type' => $type,
            'token' => $rawToken,
        ]);

        return response()->json($token, 201);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider_id' => 'required|exists:captcha_providers,id',
            'token' => 'required|string',
        ]);

        $user = $request->user();
        if ($user && ! $user->isSuperAdmin()) {
            $provider = \App\Models\CaptchaProvider::find($data['provider_id']);
            if ($provider && $provider->user_id !== $user->id) {
                abort(403, 'You do not own this captcha provider.');
            }
        }

        return CaptchaToken::create($data);
    }

    public function update(Request $request, CaptchaToken $captchaToken)
    {
        $data = $request->validate([
            'provider_id' => 'exists:captcha_providers,id',
            'token' => 'string',
        ]);

        $captchaToken->update($data);

        return $captchaToken;
    }

    public function destroy(CaptchaToken $captchaToken)
    {
        $captchaToken->delete();

        return response()->noContent();
    }

    /**
     * Public API for the Java bot to fetch enabled captcha providers.
     * Validates the X-Bot-Secret header against settings.captcha_bot_secret.
     */
    public function botProviders(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = Setting::instance();
        $secret = $settings->captcha_bot_secret;

        if (! $secret || $request->header('X-Bot-Secret') !== $secret) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $providers = CaptchaProvider::where('enabled', true)
            ->whereNotNull('api_key')
            ->get(['id', 'name', 'type', 'api_key']);

        return response()->json($providers->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type->value,
            'apiKey' => $p->api_key,
        ]));
    }

    /**
     * Public API for the Java bot to push a solved captcha token.
     * Validates the X-Bot-Secret header and enforces the max token pool limit.
     */
    public function botStore(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = Setting::instance();
        $secret = $settings->captcha_bot_secret;

        if (! $secret || $request->header('X-Bot-Secret') !== $secret) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'provider_id' => 'required|integer|exists:captcha_providers,id',
            'token' => 'required|string',
        ]);

        // Purge expired tokens first, then check the cap
        CaptchaToken::where('created_at', '<=', CaptchaPoolExpiry::cutoff())->delete();

        $maxTokens = $settings->captcha_generator_max_tokens ?? 5;
        if (CaptchaToken::count() >= $maxTokens) {
            return response()->json(['message' => 'Token pool full'], 429);
        }

        $token = CaptchaToken::create($data);

        return response()->json($token, 201);
    }

    /**
     * Public API to consume a single captcha token.
     * Purges expired tokens, then returns the latest valid token.
     * Uses database locking to prevent multiple clients from getting the same token.
     */
    public function consume()
    {
        // Open, unauthenticated, and serving the captcha_tokens pool — so it answers to the
        // same master switch as /api/captcha/request. Without this, disabling every provider
        // stopped one delivery path while leaving this one handing out banked tokens.
        if (CaptchaDeliveryGate::shut()) {
            return response()->json(['message' => CaptchaDeliveryGate::reason()], 503);
        }

        CaptchaToken::where('created_at', '<=', CaptchaPoolExpiry::cutoff())->delete();

        return \DB::transaction(function () {
            $token = CaptchaToken::with('provider')
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return response()->json(['message' => 'No captcha tokens available'], 404);
            }

            $responseData = [
                'id' => $token->id,
                'token' => $token->token,
                'provider' => $token->provider?->name,
                'provider_id' => $token->provider_id,
                'created_at' => $token->created_at,
                'updated_at' => $token->updated_at,
            ];

            // Delete immediately after serving
            $token->delete();

            return response()->json($responseData);
        });
    }
}
