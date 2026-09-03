<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaProviderType;
use App\Http\Controllers\Controller;
use App\Models\CaptchaProvider;
use App\Services\CaptchaProviderService;
use App\Services\CaptchaSolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CaptchaProviderController extends Controller
{
    public function __construct(
        protected CaptchaProviderService $service,
        protected CaptchaSolverService $solver,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('captcha.read');

        $user = $request->user();
        $search = $request->query('search');
        $query = CaptchaProvider::query();

        if (! $user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->latest()->paginate($request->query('per_page', 10));
    }

    public function store(Request $request)
    {
        Gate::authorize('captcha.write');
        $data = $request->validate([
            'name' => 'required|string',
            'type' => ['required', Rule::enum(CaptchaProviderType::class)],
            'enabled' => 'boolean',
            'api_key' => 'nullable|string',
            'solver_threads' => 'integer|min:1',
            'params' => 'nullable|array',
        ]);

        if ($denied = $this->denyInHouseToNonAdmin($request, $data['type'] ?? null)) {
            return $denied;
        }

        $data['user_id'] = $request->user()->id;

        $max = config('captcha.max_providers', 1000);
        if (CaptchaProvider::count() >= $max) {
            return response()->json([
                'message' => "Maximum number of captcha providers ({$max}) reached.",
            ], 422);
        }

        return $this->service->create($data);
    }

    public function update(Request $request, CaptchaProvider $captchaProvider)
    {
        Gate::authorize('captcha.write');

        $user = $request->user();
        if (! $user->isSuperAdmin() && $captchaProvider->user_id !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'string',
            'type' => [Rule::enum(CaptchaProviderType::class)],
            'enabled' => 'boolean',
            'api_key' => 'nullable|string',
            'solver_threads' => 'integer|min:1',
            'params' => 'nullable|array',
        ]);

        if ($denied = $this->denyInHouseToNonAdmin($request, $data['type'] ?? null)) {
            return $denied;
        }

        return $this->service->update($captchaProvider, $data);
    }

    /**
     * The in-house solver is a super-admin subsystem: its page, its API and its
     * sidecar are all gated on bot.manage. Providers, however, are readable and
     * writable by managers and agents — so without this a non-admin could create an
     * in-house row and drive the solver anyway.
     *
     * Returns a 403 response when the type is refused, or null to continue.
     */
    private function denyInHouseToNonAdmin(Request $request, ?string $type): ?JsonResponse
    {
        if ($type !== CaptchaProviderType::InHouse->value || $request->user()->can('bot.manage')) {
            return null;
        }

        return response()->json([
            'message' => 'The in-house solver is restricted to administrators.',
        ], 403);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        Gate::authorize('captcha.write');

        $data = $request->validate(['enabled' => 'required|boolean']);

        $user = $request->user();
        $query = CaptchaProvider::query();

        if (! $user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        }

        $count = $query->update(['enabled' => $data['enabled']]);

        return response()->json(['updated' => $count]);
    }

    public function balance(CaptchaProvider $captchaProvider): JsonResponse
    {
        Gate::authorize('captcha.read');

        $user = request()->user();
        if (! $user->isSuperAdmin() && $captchaProvider->user_id !== $user->id) {
            abort(403);
        }

        try {
            $balance = $this->solver->getBalance($captchaProvider);

            return response()->json(['balance' => $balance]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(CaptchaProvider $captchaProvider)
    {
        Gate::authorize('captcha.write');

        $user = request()->user();
        if (! $user->isSuperAdmin() && $captchaProvider->user_id !== $user->id) {
            abort(403);
        }

        $this->service->delete($captchaProvider);

        return response()->noContent();
    }
}
