<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountPdf;
use App\Models\AccountSession;
use App\Models\BypassIp;
use App\Models\OtpCode;
use App\Models\PdfEditProfile;
use App\Models\Setting;
use App\Services\Captcha\CaptchaEncryptionService;
use App\Services\Pdf\PdfFieldEditor;
use App\Support\CaptchaTokenTransformer;
use App\Support\JwtClaimExtractor;
use App\Support\VisaFormPdfParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;

class ApiTesterController extends Controller
{
    private const IVAC_HOST = 'api.ivacbd.com';

    private const BASE_URL = 'https://api.ivacbd.com/iams/api/v1';

    /**
     * Account-registration endpoints, extracted from the live bundle's signup pages
     * (/signup -> /signup/info -> /signup/password -> /signup/consent).
     *
     * They are deliberately NOT part of settings.ivac_endpoints: the Java bot never registers an
     * account, so a rotation here can only ever affect the tester, and adding them to the config
     * payload would ship dead keys to every worker. Each is still overridable per-call from the
     * tester's URL box.
     */
    private const SIGNUP_SEND_OTP_PATH = '/otp/signupOtp';

    private const SIGNUP_VERIFY_OTP_PATH = '/otp/verify-otp';

    private const SIGNUP_PATH = '/auth/signup';

    /**
     * Device identifier sent on every IVAC request, mirroring the browser client
     * which attaches X-Device-ID to all calls (including file uploads). A single
     * stable value is reused across sign-in and subsequent file operations so the
     * session and uploads share one device identity.
     */
    private const IVAC_DEVICE_ID = 'ipmsApiTester01x';

    /**
     * The bundle-extracted IVAC endpoint paths + rotating headers, merged over the compiled-in
     * defaults. This is the exact same source of truth PublicConfigController/ConfigExportService
     * deliver to the Java bot (settings.ivac_endpoints), so a manual edit on /ivac-endpoints or an
     * Algorithm Monitor sync is reflected in every api-tester call immediately — no separate
     * hardcoded paths/headers to fall out of sync.
     *
     * @return array<string, string>
     */
    private function ivacEndpoints(): array
    {
        return Setting::instance()->ivacEndpointsWithDefaults();
    }

    /**
     * Resolve a path/URL to an absolute IVAC request target. Every call site normally passes a
     * path relative to BASE_URL, but the api-tester's Postman-style URL field lets a tester type
     * (or paste) an already-absolute URL to point a single test call at a different host — this
     * is the one place that decides which of the two it got.
     */
    private function resolveUrl(string $pathOrUrl): string
    {
        return str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')
            ? $pathOrUrl
            : self::BASE_URL.$pathOrUrl;
    }

    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Account::query()->where('status', 'running');

        if (($visibleIds = $user->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        $accounts = $query
            ->with(['pdfs' => fn ($q) => $q
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->select(['id', 'account_id', 'name', 'is_primary', 'original_size', 'optimized_size'])])
            ->orderBy('phone')
            ->get(['id', 'phone', 'email', 'tag', 'appointment_id']);

        $accountIds = $accounts->pluck('id');
        $sessions = AccountSession::whereIn('account_id', $accountIds)
            ->get(['account_id', 'jwt_expires_at'])
            ->keyBy('account_id');

        $accounts = $accounts->map(fn ($a) => [
            'id' => $a->id,
            'phone' => $a->phone,
            'email' => $a->email,
            'tag' => $a->tag,
            'appointment_id' => $a->appointment_id,
            'jwt_expires_at' => $sessions->get($a->id)?->jwt_expires_at?->toISOString(),
            'pdfs' => $a->pdfs->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_primary' => (bool) $p->is_primary,
                'size' => $p->optimized_size ?? $p->original_size,
            ])->values(),
        ]);

        $phones = $accounts->pluck('phone');
        $pdfProfiles = PdfEditProfile::whereIn('phone', $phones)
            ->get(['phone', 'surname', 'given_name', 'passport_no', 'pdf_phone', 'email'])
            ->keyBy('phone')
            ->map(fn ($p) => [
                'surname'     => $p->surname,
                'given_name'  => $p->given_name,
                'passport_no' => $p->passport_no,
                'pdf_phone'   => $p->pdf_phone,
                'email'       => $p->email,
            ]);

        $bypassIps = BypassIp::query()
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get(['id', 'label', 'ip', 'is_default']);

        $setting = Setting::instance();

        return response()->json([
            'accounts'        => $accounts,
            'pdf_profiles'    => $pdfProfiles,
            'bypass_ips'      => $bypassIps,
            'cf_worker_url'   => config('services.cf_worker.url'),
            'cf_worker_secret' => config('services.cf_worker.secret'),
            // Live protocol constants — same values the Java bot reads from /api/config, so the
            // tester's own IVAC calls (and any hardcoded frontend fallbacks) stay in sync with
            // whatever the Algorithm Monitor last extracted or an admin last edited on /ivac-endpoints.
            'ivac_endpoints'    => $setting->ivacEndpointsWithDefaults(),
            'ivac_base_url'     => self::BASE_URL,
            'reserve_slot_id'   => $setting->reserve_slot_id,
            'payment_config_id' => $setting->payment_config_id,
        ]);
    }

    /**
     * Fire a registration OTP for an as-yet-unregistered phone or email.
     *
     * Unlike the booking flow's forgot-password OTP, this one carries no JWT (there is no account
     * yet) and is gated by a RAW Turnstile token in the x-token header — not the encrypted `c`
     * body field sign-in uses. The response's data.requestId is what signupVerifyOtp() must echo.
     */
    public function signupSendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'channel' => 'required|in:phone,email',
            'phone' => 'required_if:channel,phone|nullable|string|max:32',
            'email' => 'required_if:channel,email|nullable|email|max:255',
            'captcha_token' => 'required|string',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $body = self::buildSignupOtpBody($data['channel'], $data['phone'] ?? null, $data['email'] ?? null);
        $extraHeaders = ['x-token: '.$data['captcha_token']];
        $path = $data['url'] ?? self::SIGNUP_SEND_OTP_PATH;

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, null, $extraHeaders)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, null, $extraHeaders);

        return response()->json($result);
    }

    /**
     * Confirm a registration OTP. Both channels must be verified before /auth/signup will accept
     * the account, and each verifies against its own requestId from the matching signupSendOtp().
     */
    public function signupVerifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'channel' => 'required|in:phone,email',
            'phone' => 'required_if:channel,phone|nullable|string|max:32',
            'email' => 'required_if:channel,email|nullable|email|max:255',
            'request_id' => 'required|string',
            'otp' => 'required|string',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $body = self::buildSignupVerifyOtpBody(
            $data['channel'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['request_id'],
            $data['otp'],
        );

        $path = $data['url'] ?? self::SIGNUP_VERIFY_OTP_PATH;

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, null)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, null);

        return response()->json($result);
    }

    /**
     * Create the IVAC account once both OTP channels are verified.
     *
     * Field names mirror the bundle's payload exactly — `surName` (capital N) and `dob`, not the
     * `surname`/`dateOfBirth` the signup form carries internally — and a blank NID is sent as a
     * literal null, which is how the site distinguishes "no NID" from an empty string.
     */
    public function signup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'phone' => 'required|string|max:32',
            'email' => 'required|email|max:255',
            'given_name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'dob' => 'required|date_format:Y-m-d',
            'nid' => 'nullable|string|max:64',
            'passport' => 'nullable|string|max:64',
            'password' => 'required|string|max:255',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $body = self::buildSignupBody($data);
        $path = $data['url'] ?? self::SIGNUP_PATH;

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, null)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, null);

        return response()->json($result);
    }

    /**
     * Pre-fill the registration form from an applicant's visa application form PDF.
     *
     * Purely local — nothing is sent to IVAC and the upload is not retained. Fields the PDF does
     * not carry come back null so the operator can type them in.
     */
    public function parseSignupPdf(Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:20480',
        ]);

        $contents = file_get_contents($request->file('pdf')->getRealPath());

        return response()->json([
            'fields' => VisaFormPdfParser::extract($contents === false ? '' : $contents),
        ]);
    }

    /**
     * Registration OTP request body.
     *
     * The bundle sends exactly ONE identifier per call and the phone channel is `PHONE`, not the
     * booking flow's `SMS` — sending both identifiers, or the booking flow's channel name, makes
     * IVAC resolve the wrong recipient.
     *
     * @return array<string, string>
     */
    public static function buildSignupOtpBody(string $channel, ?string $phone, ?string $email): array
    {
        return $channel === 'email'
            ? ['email' => (string) $email, 'otpChannel' => 'EMAIL']
            : ['phone' => (string) $phone, 'otpChannel' => 'PHONE'];
    }

    /**
     * Registration OTP confirmation body. The code field is `code`, not `otp`, and each channel
     * echoes the requestId minted by its own buildSignupOtpBody() call.
     *
     * @return array<string, string>
     */
    public static function buildSignupVerifyOtpBody(
        string $channel,
        ?string $phone,
        ?string $email,
        string $requestId,
        string $code,
    ): array {
        return $channel === 'email'
            ? ['requestId' => $requestId, 'email' => (string) $email, 'code' => $code, 'otpChannel' => 'EMAIL']
            : ['requestId' => $requestId, 'phone' => (string) $phone, 'code' => $code, 'otpChannel' => 'PHONE'];
    }

    /**
     * Account-creation body, keyed exactly as the bundle sends it: `surName` (capital N) and `dob`,
     * NOT the `surname`/`dateOfBirth` the signup wizard carries in its own store. A blank NID goes
     * out as a literal null — that is how the site distinguishes "no NID" from an empty string.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function buildSignupBody(array $data): array
    {
        $nid = trim((string) ($data['nid'] ?? ''));

        return [
            'phone' => (string) $data['phone'],
            'email' => (string) $data['email'],
            'nid' => $nid !== '' ? $nid : null,
            'passport' => (string) ($data['passport'] ?? ''),
            'givenName' => (string) $data['given_name'],
            'surName' => (string) $data['surname'],
            'dob' => (string) $data['dob'],
            'password' => (string) $data['password'],
        ];
    }

    public function signin(Request $request, CaptchaEncryptionService $encryptor): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'captcha_token' => 'nullable|string',
            'raw_captcha_token' => 'nullable|string',
            'spoofed_ip' => 'nullable|ip',
            'url' => 'nullable|string|max:2048',
        ]);

        $account = Account::findOrFail($data['account_id']);
        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);

        if ($account->password === null) {
            return response()->json([
                'error' => 'Account password could not be decrypted (APP_KEY mismatch). Please update the password via the account edit form.',
            ], 422);
        }

        $body = [
            'phone' => $account->phone,
            'email' => $account->email,
            'password' => $account->password,
        ];

        // Encrypt captcha token: raw_captcha_token is auto-encrypted via PHP v2.
        // captcha_token is sent as-is (already encrypted by caller).
        if (!empty($data['raw_captcha_token'])) {
            $body['c'] = CaptchaTokenTransformer::transformV2($data['raw_captcha_token'], 4, 26);
        } elseif (!empty($data['captcha_token'])) {
            $body['c'] = $data['captcha_token'];
        }

        $endpoints = $this->ivacEndpoints();
        $path = $data['url'] ?? $endpoints['signin'];

        // The bundle attaches x-sec-navigation-state to every sign-in POST; without it the tester
        // diverges from the real browser request the Java bot mirrors.
        $extraHeaders = ['x-sec-navigation-state: '.$endpoints['signinNavState']];
        if (!empty($data['spoofed_ip'])) {
            $extraHeaders[] = 'X-Forwarded-For: '.$data['spoofed_ip'];
            $extraHeaders[] = 'X-Real-IP: '.$data['spoofed_ip'];
        }

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, null, $extraHeaders)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, null, $extraHeaders);

        if ($result['status_code'] >= 200 && $result['status_code'] < 300) {
            $responseData = is_array($result['body']) ? ($result['body']['data'] ?? []) : [];
            $jwtToken = is_array($responseData) ? ($responseData['accessToken'] ?? null) : null;
            if ($jwtToken) {
                $claims = JwtClaimExtractor::extract($jwtToken);
                AccountSession::updateOrCreate(
                    ['phone' => $account->phone],
                    [
                        'account_id' => $account->id,
                        'jwt_token' => $jwtToken,
                        'jwt_generated_at' => $claims['iat'],
                        'jwt_expires_at' => $claims['exp'],
                        'request_id' => is_array($responseData) ? ($responseData['requestId'] ?? null) : null,
                    ]
                );
            }
        }

        return response()->json($result);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'channel' => 'nullable|in:phone,email',
            'url' => 'nullable|string|max:2048',
        ]);

        $account = Account::findOrFail($data['account_id']);
        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $channel = strtoupper($data['channel'] ?? 'phone');

        $body = [
            'phone' => $account->phone,
            'email' => $account->email,
            'otpChannel' => $channel,
        ];

        $path = $data['url'] ?? $this->ivacEndpoints()['sendOtp'];
        $result = $this->ivacRequest('POST', $path, $body, $bypassIp, null);

        return response()->json($result);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'request_id' => 'required|string',
            'otp' => 'required|string',
            'channel' => 'nullable|in:phone,email',
            'url' => 'nullable|string|max:2048',
        ]);

        $account = Account::findOrFail($data['account_id']);
        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $channel = strtoupper($data['channel'] ?? 'phone');

        $body = [
            'requestId' => $data['request_id'],
            'phone' => $account->phone,
            'code' => $data['otp'],
            'otpChannel' => $channel,
        ];

        $path = $data['url'] ?? $this->ivacEndpoints()['verifyOtp'];
        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, $data['access_token'])
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, $data['access_token']);

        return response()->json($result);
    }

    /**
     * Manual Turnstile token login: takes a raw Turnstile token from a real browser,
     * encrypts it with PHP v2, and signs in.
     *
     * POST /api/ivac/signin-manual
     * Body: { account_id, raw_turnstile_token }
     */
    public function signinManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'raw_turnstile_token' => 'required|string|min:10',
        ]);

        $account = Account::findOrFail($data['account_id']);

        if ($account->password === null) {
            return response()->json([
                'error' => 'Account password could not be decrypted.',
            ], 422);
        }

        $encrypted = CaptchaTokenTransformer::transformV2($data['raw_turnstile_token'], 4, 26);

        $body = [
            'phone' => $account->phone,
            'email' => $account->email,
            'password' => $account->password,
            'c' => $encrypted,
        ];

        $endpoints = $this->ivacEndpoints();
        $extraHeaders = ['x-sec-navigation-state: '.$endpoints['signinNavState']];

        $result = $this->ivacRequestViaCloudscraper('POST', $endpoints['signin'], $body, null, $extraHeaders);

        if ($result['status_code'] >= 200 && $result['status_code'] < 300) {
            $responseData = is_array($result['body']) ? ($result['body']['data'] ?? $result['body']) : [];
            $jwtToken = is_array($responseData) ? ($responseData['accessToken'] ?? null) : null;
            $requestId = is_array($responseData) ? ($responseData['requestId'] ?? null) : null;

            if ($jwtToken) {
                $claims = JwtClaimExtractor::extract($jwtToken);
                AccountSession::updateOrCreate(
                    ['phone' => $account->phone],
                    [
                        'account_id' => $account->id,
                        'jwt_token' => $jwtToken,
                        'jwt_generated_at' => $claims['iat'],
                        'jwt_expires_at' => $claims['exp'],
                        'request_id' => $requestId,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Login successful. OTP has been sent to your phone.',
                'data' => [
                    'accessToken' => $jwtToken,
                    'requestId' => $requestId,
                    'phone' => $account->phone,
                    'expires_at' => $claims['exp'] ?? null,
                ],
                'raw' => $result,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Login failed.',
            'raw' => $result,
        ], $result['status_code'] >= 400 ? $result['status_code'] : 400);
    }

    /**
     * Full login + OTP verify flow: signs in, gets requestId, verifies OTP.
     *
     * POST /api/ivac/signin-and-verify-otp
     * Body: { account_id, raw_turnstile_token, otp_code }
     */
    public function signinAndVerifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
            'raw_turnstile_token' => 'required|string|min:10',
            'otp_code' => 'required|string|size:6',
        ]);

        $account = Account::findOrFail($data['account_id']);

        if ($account->password === null) {
            return response()->json(['error' => 'Account password could not be decrypted.'], 422);
        }

        // Step 1: Sign in
        $encrypted = CaptchaTokenTransformer::transformV2($data['raw_turnstile_token'], 4, 26);
        $endpoints = $this->ivacEndpoints();
        $extraHeaders = ['x-sec-navigation-state: '.$endpoints['signinNavState']];

        $signinBody = [
            'phone' => $account->phone,
            'email' => $account->email,
            'password' => $account->password,
            'c' => $encrypted,
        ];

        $signinResult = $this->ivacRequestViaCloudscraper('POST', $endpoints['signin'], $signinBody, null, $extraHeaders);

        if ($signinResult['status_code'] < 200 || $signinResult['status_code'] >= 300) {
            return response()->json([
                'success' => false,
                'step' => 'signin',
                'message' => 'Sign-in failed.',
                'raw' => $signinResult,
            ], $signinResult['status_code'] >= 400 ? $signinResult['status_code'] : 400);
        }

        $responseData = is_array($signinResult['body']) ? ($signinResult['body']['data'] ?? $signinResult['body']) : [];
        $jwtToken = is_array($responseData) ? ($responseData['accessToken'] ?? null) : null;
        $requestId = is_array($responseData) ? ($responseData['requestId'] ?? null) : null;

        if (! $jwtToken || ! $requestId) {
            return response()->json([
                'success' => false,
                'step' => 'signin',
                'message' => 'Sign-in did not return token/requestId.',
                'raw' => $signinResult,
            ], 400);
        }

        // Step 2: Verify OTP
        $otpBody = [
            'requestId' => $requestId,
            'phone' => $account->phone,
            'code' => $data['otp_code'],
            'otpChannel' => 'PHONE',
        ];

        $verifyResult = $this->ivacRequestViaCloudscraper('POST', '/otp/verifySigninOtp', $otpBody, $jwtToken);

        if ($verifyResult['status_code'] >= 200 && $verifyResult['status_code'] < 300) {
            $verifyData = is_array($verifyResult['body']) ? ($verifyResult['body']['data'] ?? $verifyResult['body']) : [];
            $newJwt = is_array($verifyData) ? ($verifyData['accessToken'] ?? $verifyData['token'] ?? $jwtToken) : $jwtToken;

            $claims = JwtClaimExtractor::extract($newJwt);
            AccountSession::updateOrCreate(
                ['phone' => $account->phone],
                [
                    'account_id' => $account->id,
                    'jwt_token' => $newJwt,
                    'jwt_generated_at' => $claims['iat'],
                    'jwt_expires_at' => $claims['exp'],
                    'request_id' => $requestId,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully.',
                'data' => [
                    'accessToken' => $newJwt,
                    'requestId' => $requestId,
                    'phone' => $account->phone,
                    'expires_at' => $claims['exp'] ?? null,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'step' => 'verify_otp',
            'message' => 'OTP verification failed.',
            'signin' => $signinResult,
            'verify' => $verifyResult,
        ], $verifyResult['status_code'] >= 400 ? $verifyResult['status_code'] : 400);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $path = $data['url'] ?? '/appointment';

        // Mirrors the site's POST /appointment (body: null) that creates the appointment
        // record the file service requires before any PDF upload. Idempotent — IVAC returns
        // the existing record when one already exists. Standard JSON headers, JWT auth.
        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, null, $bypassIp, $data['access_token'])
            : $this->ivacRequestViaCloudscraper('POST', $path, null, $data['access_token']);

        $status = $result['status_code'] ?? 0;
        if (isset($data['account_id']) && $status >= 200 && $status < 300) {
            $appointmentData = is_array($result['body']) ? ($result['body']['data'] ?? null) : null;
            $account = Account::find($data['account_id']);
            if ($account && is_array($appointmentData)) {
                $appointmentId = $appointmentData['appointmentId'] ?? null;
                if ($appointmentId) {
                    $account->update([
                        'appointment_id' => $appointmentId,
                        'appointment_id_updated_at' => now(),
                    ]);
                }
            }
        }

        return response()->json($result);
    }

    public function bookingConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $path = $data['url'] ?? $this->ivacEndpoints()['getBookingConfig'];
        $result = $bypassIp
            ? $this->ivacRequest('GET', $path, null, $bypassIp, $data['access_token'])
            : $this->ivacRequestViaCloudscraper('GET', $path, null, $data['access_token']);

        if (isset($data['account_id']) && ($result['status_code'] ?? 0) === 200) {
            $configData = is_array($result['body']) ? ($result['body']['data'] ?? null) : null;
            $account = Account::find($data['account_id']);
            if ($account && is_array($configData)) {
                $appointmentId = $configData['appointmentId'] ?? null;
                if ($appointmentId) {
                    $account->update([
                        'appointment_id' => $appointmentId,
                        'appointment_id_updated_at' => now(),
                    ]);
                }
                AccountSession::where('phone', $account->phone)->update([
                    'last_booking_config' => ['data' => $configData, 'fetched_at' => now()->toIso8601String()],
                ]);
            }
        }

        return response()->json($result);
    }

    public function reserveSlot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'captcha_token' => 'required|string',
            'appointment_date' => 'required|string',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $body = [
            'c' => $data['captcha_token'],
            'appointmentDate' => $data['appointment_date'],
        ];

        $setting = Setting::instance();

        if (!empty($data['url'])) {
            $path = $data['url'];
        } else {
            // Reserve endpoint embeds the fixed, deployment-scoped slot ID in the path:
            // POST /slots/{reserveSlotId}/reserve-slot (portal setting, rotates on IVAC redeploy).
            $reserveSlotId = $setting->reserve_slot_id;
            if (empty($reserveSlotId)) {
                return response()->json(['error' => 'reserve_slot_id is not set in portal settings.'], 422);
            }
            $path = str_replace('{reserveSlotId}', rawurlencode($reserveSlotId), $this->ivacEndpoints()['reserveSlot']);
        }

        // The bundle attaches a fixed x-v-request-meta header to this call only (settings.reserve_request_meta,
        // bundle-synced alongside reserveSlotId) — the Java bot sends it on every reserve-slot POST.
        $extraHeaders = ['x-v-request-meta: '.($setting->reserve_request_meta ?: 'windos.s')];

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, $data['access_token'], $extraHeaders)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, $data['access_token'], $extraHeaders);

        return response()->json($result);
    }

    public function initiatePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'appointment_id' => 'required|string',
            'gateway' => 'nullable|in:dg-epay,ssl',
            'payment_slot_id' => 'nullable|string',
            'captcha_token' => 'nullable|string',
            'url' => 'nullable|string|max:2048',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $gateway = $data['gateway'] ?? 'dg-epay';
        $body = ['appointmentId' => $data['appointment_id']];

        $extraHeaders = [];
        if (! empty($data['captcha_token'])) {
            $extraHeaders[] = 'x-token: '.$data['captcha_token'];
        }

        // Default to the portal's synced payment_config_id (what the bot uses) when the caller
        // leaves the field blank, rather than requiring it to be pasted in by hand every time.
        $paymentSlotId = $data['payment_slot_id'] ?? null;
        if (empty($paymentSlotId)) {
            $paymentSlotId = Setting::instance()->payment_config_id;
        }

        $path = $data['url'] ?? self::buildPaymentInitiatePath($gateway, $paymentSlotId, $this->ivacEndpoints()['payment']);

        $result = $bypassIp !== null
            ? $this->ivacRequest('POST', $path, $body, $bypassIp, $data['access_token'], $extraHeaders)
            : $this->ivacRequestViaCloudscraper('POST', $path, $body, $data['access_token'], $extraHeaders);

        return response()->json($result);
    }

    /**
     * Build the payment initiate path exactly as IVAC's bundle does.
     *
     * The bundle posts dg-epay to /payment/{uuid}/dg-epay/initiate, where the UUID is a
     * deployment-scoped constant baked (RC4-obfuscated) into the bundle and rotates on
     * redeploy. ssl (and dg-epay with no UUID supplied) fall back to /payment/{gateway}/initiate.
     * $template is the bundle-extracted settings.ivac_endpoints['payment'] value (falls back to
     * the compiled-in default when omitted, e.g. from existing callers/tests).
     */
    public static function buildPaymentInitiatePath(string $gateway, ?string $paymentSlotId, ?string $template = null): string
    {
        $paymentSlotId = trim((string) $paymentSlotId);

        if ($gateway === 'dg-epay' && $paymentSlotId !== '') {
            $template ??= '/payment/{paymentConfigId}/dg-epay/initiate';

            return str_replace('{paymentConfigId}', rawurlencode($paymentSlotId), $template);
        }

        return '/payment/'.$gateway.'/initiate';
    }

    public function paymentCallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'callback_url' => 'required|string|max:4096',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $result = $this->ivacCallbackGet($data['callback_url'], $bypassIp);

        return response()->json($result);
    }

    public function fileOverview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'account_id' => 'nullable|integer|exists:accounts,id',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $result = $bypassIp
            ? $this->ivacRequest('POST', '/file/overview', null, $bypassIp, $data['access_token'])
            : $this->ivacRequestViaCloudscraper('POST', '/file/overview', null, $data['access_token']);

        if (isset($data['account_id']) && ($result['status_code'] ?? 0) === 200) {
            $items = is_array($result['body']) ? ($result['body']['data'] ?? null) : null;
            $account = Account::find($data['account_id']);
            if ($account && is_array($items)) {
                AccountSession::where('phone', $account->phone)->update([
                    'last_file_overview' => ['items' => $items, 'fetched_at' => now()->toIso8601String()],
                ]);
            }
        }

        return response()->json($result);
    }

    public function editPdf(Request $request, PdfFieldEditor $editor): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $request->validate([
            'pdf'         => 'required|file|mimes:pdf|max:20480',
            'surname'     => 'nullable|string|max:100',
            'given_name'  => 'nullable|string|max:100',
            'passport_no' => 'nullable|string|max:50',
            'nid'         => 'nullable|string|max:50',
            'phone'       => 'nullable|string|max:30',
            'email'       => 'nullable|string|max:150',
        ]);

        $fields = array_filter(Arr::only($data, PdfFieldEditor::FIELDS));
        if ($fields === []) {
            return response()->json(['error' => 'At least one field is required.'], 422);
        }

        $edited = $editor->edit((string) file_get_contents($request->file('pdf')->getRealPath()), $fields);
        if ($edited === null) {
            return response()->json(['error' => 'PDF edit failed — none of the fields could be located.'], 500);
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'edited_pdf_').'.pdf';
        file_put_contents($outputPath, $edited);

        return response()->download($outputPath, 'edited_passport.pdf', [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function uploadPdf(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'is_primary' => 'nullable|boolean',
            'device_id' => 'nullable|string|max:100',
            'captcha_token' => 'nullable|string',
            'pdf' => 'required|file|mimes:pdf|max:20480',
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $isPrimary = filter_var($data['is_primary'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $file = $request->file('pdf');
        $result = $this->ivacFileUpload($file->getRealPath(), $file->getClientOriginalName(), $bypassIp, $data['access_token'], $isPrimary, $data['captcha_token'] ?? null);

        return response()->json($result);
    }

    /**
     * Upload one of the account's already-attached PDFs (stored Base64) to IVAC's file service.
     * The isPrimary flag comes from the stored PDF row, so the frontend never re-picks a file.
     */
    public function uploadAccountPdf(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'pdf_id' => 'required|integer|exists:account_pdfs,id',
            'captcha_token' => 'nullable|string',
        ]);

        $pdf = AccountPdf::findOrFail($data['pdf_id']);
        $binary = base64_decode($pdf->deliverable_base64 ?? '', true);

        if ($binary === false || $binary === '') {
            return response()->json(['error' => 'PDF could not be decoded from storage.'], 422);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'ivac_pdf_').'.pdf';
        file_put_contents($tmpPath, $binary);

        try {
            $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
            $filename = str_ends_with(strtolower($pdf->name), '.pdf') ? $pdf->name : $pdf->name.'.pdf';
            $result = $this->ivacFileUpload($tmpPath, $filename, $bypassIp, $data['access_token'], (bool) $pdf->is_primary, $data['captcha_token'] ?? null);
        } finally {
            @unlink($tmpPath);
        }

        $result['pdf_id'] = $pdf->id;

        return response()->json($result);
    }

    public function setBookingConfig(Request $request): JsonResponse
    {
        $cities = \App\Support\IvacBookingCities::all();

        $data = $request->validate([
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'access_token' => 'required|string',
            'city' => 'required|string|in:'.implode(',', array_keys($cities)),
        ]);

        $bypassIp = $this->resolveBypassIp($data['bypass_ip_id'] ?? null);
        $path = $this->ivacEndpoints()['bookingConfig'];
        $result = $bypassIp
            ? $this->ivacRequest('POST', $path, $cities[$data['city']], $bypassIp, $data['access_token'])
            : $this->ivacRequestViaCloudscraper('POST', $path, $cities[$data['city']], $data['access_token']);

        return response()->json($result);
    }

    public function fetchOtp(int $accountId): JsonResponse
    {
        $account = Account::findOrFail($accountId);

        return response()->json($this->latestOtpFor($account->phone));
    }

    /**
     * Latest ingested OTP for a raw phone number. The signup flow needs this because the number
     * being registered has no accounts row yet, so fetchOtp()'s account lookup cannot serve it.
     */
    public function fetchOtpForPhone(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:32']);

        return response()->json($this->latestOtpFor($data['phone']));
    }

    /**
     * @return array{otp_code: ?string, message: ?string, created_at: ?string}
     */
    private function latestOtpFor(string $phone): array
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first(['otp_code', 'message', 'created_at']);

        if (! $otp) {
            return ['otp_code' => null, 'message' => null, 'created_at' => null];
        }

        return [
            'otp_code' => $otp->otp_code,
            'message' => $otp->message,
            'created_at' => $otp->created_at?->toIso8601String(),
        ];
    }

    public function getSession(int $accountId): JsonResponse
    {
        $account = Account::findOrFail($accountId);
        $session = AccountSession::where('phone', $account->phone)->first();

        return response()->json([
            'jwt_token' => $session?->jwt_token,
            'jwt_expires_at' => $session?->jwt_expires_at?->toIso8601String(),
            'jwt_generated_at' => $session?->jwt_generated_at?->toIso8601String(),
            'request_id' => $session?->request_id,
            'last_booking_config' => $session?->last_booking_config,
            'last_file_overview' => $session?->last_file_overview,
        ]);
    }

    public function updateSession(Request $request, int $accountId): JsonResponse
    {
        $data = $request->validate(['jwt_token' => 'required|string']);
        $account = Account::findOrFail($accountId);
        $claims = JwtClaimExtractor::extract($data['jwt_token']);

        $session = AccountSession::updateOrCreate(
            ['phone' => $account->phone],
            [
                'account_id' => $account->id,
                'jwt_token' => $data['jwt_token'],
                'jwt_generated_at' => $claims['iat'],
                'jwt_expires_at' => $claims['exp'],
            ]
        );

        return response()->json([
            'jwt_token' => $session->jwt_token,
            'jwt_expires_at' => $session->jwt_expires_at?->toIso8601String(),
            'jwt_generated_at' => $session->jwt_generated_at?->toIso8601String(),
            'request_id' => $session->request_id,
        ]);
    }

    public function savePdfProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'       => 'required|string|max:30',
            'surname'     => 'nullable|string|max:255',
            'given_name'  => 'nullable|string|max:255',
            'passport_no' => 'nullable|string|max:100',
            'pdf_phone'   => 'nullable|string|max:30',
            'email'       => 'nullable|string|max:255',
        ]);

        $profile = PdfEditProfile::updateOrCreate(
            ['phone' => $data['phone']],
            [
                'surname'     => $data['surname'] ?? null,
                'given_name'  => $data['given_name'] ?? null,
                'passport_no' => $data['passport_no'] ?? null,
                'pdf_phone'   => $data['pdf_phone'] ?? null,
                'email'       => $data['email'] ?? null,
            ]
        );

        return response()->json(['ok' => true, 'phone' => $profile->phone]);
    }

    public function workerSignin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id'    => 'required|integer|exists:accounts,id',
            'worker_url'    => 'required|url',
            'captcha_token' => 'nullable|string',
            'worker_secret' => 'nullable|string',
        ]);

        $account = Account::findOrFail($data['account_id']);

        if ($account->password === null) {
            return response()->json([
                'error' => 'Account password could not be decrypted (APP_KEY mismatch).',
            ], 422);
        }

        $endpoints = $this->ivacEndpoints();
        $workerUrl = rtrim($data['worker_url'], '/');
        $url = $workerUrl.'/iams/api/v1'.$endpoints['signin'];

        $body = [
            'phone'    => $account->phone,
            'email'    => $account->email,
            'password' => $account->password,
        ];

        if (!empty($data['captcha_token'])) {
            $body['c'] = $data['captcha_token'];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Device-ID: '.self::IVAC_DEVICE_ID,
            'x-sec-navigation-state: '.$endpoints['signinNavState'],
        ];

        if (!empty($data['worker_secret'])) {
            $headers[] = 'X-Worker-Secret: '.$data['worker_secret'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $start    = microtime(true);
        $raw      = curl_exec($ch);
        $duration = (int) ((microtime(true) - $start) * 1000);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($ch) ?: null;
        curl_close($ch);

        $rawString = is_string($raw) ? $raw : '';
        $decoded   = json_decode($rawString, true);

        if ($status >= 200 && $status < 300) {
            $responseData = is_array($decoded) ? ($decoded['data'] ?? []) : [];
            $jwtToken = is_array($responseData) ? ($responseData['accessToken'] ?? null) : null;
            if ($jwtToken) {
                $claims = JwtClaimExtractor::extract($jwtToken);
                AccountSession::updateOrCreate(
                    ['phone' => $account->phone],
                    [
                        'account_id'       => $account->id,
                        'jwt_token'        => $jwtToken,
                        'jwt_generated_at' => $claims['iat'],
                        'jwt_expires_at'   => $claims['exp'],
                        'request_id'       => is_array($responseData) ? ($responseData['requestId'] ?? null) : null,
                    ]
                );
            }
        }

        return response()->json([
            'method'      => 'POST',
            'url'         => $url,
            'bypass_ip'   => null,
            'status_code' => $status,
            'body'        => $decoded ?? $rawString,
            'raw'         => $rawString,
            'duration_ms' => $duration,
            'error'       => $error,
        ]);
    }

    private function resolveBypassIp(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $row = BypassIp::find($id);

        return $row?->ip;
    }

    /**
     * Configure the curl transport for a request. When a CF bypass IP is selected the
     * request is pinned to that origin IP via DNS override. When no bypass IP is selected
     * the request goes to api.ivacbd.com directly but is tunnelled through the BD proxy
     * configured in the captcha Algorithm Monitor (settings.captcha_bd_proxy_url), so the
     * call still exits from a Bangladesh IP rather than the portal host.
     *
     * @param  \CurlHandle  $ch
     */
    private function applyTransport($ch, ?string $bypassIp): void
    {
        $transport = $this->resolveTransport($bypassIp);

        if ($transport['mode'] === 'resolve') {
            curl_setopt($ch, CURLOPT_RESOLVE, [self::IVAC_HOST.':443:'.$transport['value']]);
        } elseif ($transport['mode'] === 'proxy') {
            curl_setopt($ch, CURLOPT_PROXY, $transport['value']);
        }
    }

    /**
     * Decide how an IVAC request should reach the origin:
     *  - 'resolve': a CF bypass IP was selected, pin the origin via DNS override.
     *  - 'proxy': no bypass IP, tunnel through the Algorithm Monitor BD proxy.
     *  - 'direct': no bypass IP and no proxy configured, go straight from the host.
     *
     * @return array{mode: 'resolve'|'proxy'|'direct', value: ?string}
     */
    public function resolveTransport(?string $bypassIp): array
    {
        if ($bypassIp !== null && $bypassIp !== '') {
            return ['mode' => 'resolve', 'value' => $bypassIp];
        }

        $proxy = Setting::instance()->captcha_bd_proxy_url;
        if (! empty($proxy)) {
            return ['mode' => 'proxy', 'value' => $proxy];
        }

        return ['mode' => 'direct', 'value' => null];
    }

    /**
     * @return array{status_code: int, body: mixed, raw: string, duration_ms: int, url: string, method: string, bypass_ip: ?string, error: ?string}
     */
    private function ivacFileUpload(string $realPath, string $filename, ?string $bypassIp, string $authToken, bool $isPrimary = true, ?string $captchaToken = null): array
    {
        $endpoints = $this->ivacEndpoints();
        $url = self::BASE_URL.$endpoints['uploadFile'];
        // Match the IVAC site's own request exactly: the frontend bundle sends only
        // Authorization (via its axios interceptor), x-token, and x-sec-runtime-state
        // on /file/upload_file (endpoint renamed from /file/upload-file — underscore).
        // It sends NO X-Device-ID (that header does not appear anywhere in the bundle)
        // and lets the browser set the multipart Content-Type + boundary. Sending the
        // extra headers makes IVAC's stricter file service reject the request with a
        // 404 "Appointment not found.", even though sign-in/OTP tolerate them.
        $headers = [
            'Authorization: Bearer '.$authToken,
            'x-sec-runtime-state: '.$endpoints['uploadRuntimeState'],
            'Expect:',
        ];

        if ($captchaToken !== null && $captchaToken !== '') {
            $headers[] = 'x-token: '.$captchaToken;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $this->applyTransport($ch, $bypassIp);

        $cfile = new \CURLFile($realPath, 'application/pdf', $filename);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['files' => $cfile, 'isPrimary' => $isPrimary ? 'true' : 'false']);

        $start = microtime(true);
        $raw = curl_exec($ch);
        $duration = (int) ((microtime(true) - $start) * 1000);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $rawString = is_string($raw) ? $raw : '';
        $decoded = json_decode($rawString, true);

        return [
            'method' => 'POST',
            'url' => $url,
            'bypass_ip' => $bypassIp,
            'status_code' => $statusCode,
            'body' => $decoded ?? $rawString,
            'raw' => $rawString,
            'duration_ms' => $duration,
            'error' => $error,
        ];
    }

    /**
     * Make an IVAC request via the cloudscraper Python helper (no bypass IP path).
     * Used when no CF bypass IP is selected — cloudscraper handles the Cloudflare
     * challenge while the BD proxy ensures the request exits from a Bangladesh IP.
     *
     * @param  array<string, mixed>|null  $body
     * @param  list<string>  $extraHeaders  Raw "Key: Value" header strings
     * @return array{status_code: int, body: mixed, raw: string, duration_ms: int, url: string, method: string, bypass_ip: ?string, error: ?string}
     */
    private function ivacRequestViaCloudscraper(string $method, string $path, ?array $body, ?string $authToken, array $extraHeaders = []): array
    {
        $url = self::BASE_URL.$path;
        $proxy = Setting::instance()->captcha_bd_proxy_url;

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Device-ID' => self::IVAC_DEVICE_ID,
        ];

        if ($authToken !== null && $authToken !== '') {
            $headers['Authorization'] = 'Bearer '.$authToken;
        }

        foreach ($extraHeaders as $header) {
            $parts = explode(': ', $header, 2);
            if (count($parts) === 2) {
                $headers[$parts[0]] = $parts[1];
            }
        }

        $scriptPath = base_path('app/Scripts/ivac_cloudscraper.py');
        $process = new Process(['python3', $scriptPath]);
        $process->setTimeout(90);
        $process->setInput(json_encode([
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'proxy' => $proxy ?: null,
        ]));
        $process->run();

        $meta = json_decode(trim($process->getOutput()), true);

        if (! is_array($meta)) {
            return [
                'method' => $method,
                'url' => $url,
                'bypass_ip' => null,
                'status_code' => 0,
                'body' => null,
                'raw' => '',
                'duration_ms' => 0,
                'error' => 'cloudscraper script failed: '.trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        }

        $rawString = (string) ($meta['raw'] ?? '');
        $decoded = json_decode($rawString, true);

        return [
            'method' => $method,
            'url' => $url,
            'bypass_ip' => null,
            'status_code' => (int) ($meta['status_code'] ?? 0),
            'body' => $decoded ?? $rawString,
            'raw' => $rawString,
            'duration_ms' => (int) ($meta['duration_ms'] ?? 0),
            'error' => $meta['error'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{status_code: int, body: mixed, raw: string, duration_ms: int, url: string, method: string, bypass_ip: ?string, error: ?string}
     */
    private function ivacRequest(string $method, string $path, ?array $body, ?string $bypassIp, ?string $authToken, array $extraHeaders = []): array
    {
        $url = self::BASE_URL.$path;
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Device-ID: '.self::IVAC_DEVICE_ID];

        if ($authToken !== null && $authToken !== '') {
            $headers[] = 'Authorization: Bearer '.$authToken;
        }

        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $this->applyTransport($ch, $bypassIp);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $start = microtime(true);
        $raw = curl_exec($ch);
        $duration = (int) ((microtime(true) - $start) * 1000);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $rawString = is_string($raw) ? $raw : '';
        $decoded = json_decode($rawString, true);

        return [
            'method' => $method,
            'url' => $url,
            'bypass_ip' => $bypassIp,
            'status_code' => $statusCode,
            'body' => $decoded ?? $rawString,
            'raw' => $rawString,
            'duration_ms' => $duration,
            'error' => $error,
        ];
    }

    /**
     * GET the post-payment gateway callback URL without following redirects, capturing the
     * Location header. A 302 (typically -> /payment/fail) means the gateway accepted it = success.
     *
     * @return array<string, mixed>
     */
    private function ivacCallbackGet(string $url, ?string $bypassIp): array
    {
        $responseHeaders = [];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: */*', 'X-Device-ID: '.self::IVAC_DEVICE_ID]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($header);
        });

        $this->applyTransport($ch, $bypassIp);

        $start = microtime(true);
        $raw = curl_exec($ch);
        $duration = (int) ((microtime(true) - $start) * 1000);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $rawString = is_string($raw) ? $raw : '';
        $decoded = json_decode($rawString, true);
        $location = $responseHeaders['location'] ?? null;

        return [
            'method' => 'GET',
            'url' => $url,
            'bypass_ip' => $bypassIp,
            'status_code' => $statusCode,
            'location' => $location,
            'body' => $decoded ?? ($rawString !== '' ? $rawString : ['status_code' => $statusCode, 'location' => $location]),
            'raw' => $rawString,
            'duration_ms' => $duration,
            'error' => $error,
        ];
    }
}
