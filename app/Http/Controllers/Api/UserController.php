<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Account;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * The eager-loaded counts every user row carries.
     *
     * @return array<string, callable|string>
     */
    private function rowCounts(): array
    {
        return [
            'accounts',
            'agents',
            'accounts as running_accounts_count' => fn ($q) => $q->where('status', 'running'),
            'paymentLinks as booked_today_count' => fn ($q) => $q->whereDate('payment_links.created_at', now()->toDateString()),
        ];
    }

    /**
     * Shape a single user for the client, revealing the stored password only to someone
     * authorised to administer that user.
     */
    private function present(Request $request, User $user): User
    {
        $user->loadCount($this->rowCounts())->load('manager:id,name');

        if ($request->user()->can('viewCredentials', $user)) {
            $user->makeVisible('plain_password');
        }

        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();
        $visibleIds = $actor->visibleUserIds();
        $search = $request->query('search');

        $query = User::withCount($this->rowCounts())->with('manager:id,name');

        // A manager sees only themselves and the agents they created; peers, peers' agents
        // and admins are outside the subtree and therefore outside the result set.
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->query('per_page', 100), 1), 200);

        $paginated = $query->latest()->paginate($perPage)->through(function (User $user) use ($actor) {
            if ($actor->can('viewCredentials', $user)) {
                $user->makeVisible('plain_password');
            }

            return $user;
        });

        return response()->json(array_merge($paginated->toArray(), $this->scopedTotals($actor)));
    }

    /**
     * Header totals, scoped to the same subtree as the list itself so a manager's summary
     * reflects their own agents rather than the whole portal.
     *
     * @return array{total_accounts: int, running_accounts: int, booked_today: int}
     */
    private function scopedTotals(User $actor): array
    {
        $visibleIds = $actor->visibleUserIds();

        $accounts = Account::query();
        $payments = PaymentLink::query()->whereDate('created_at', now()->toDateString());

        if ($visibleIds !== null) {
            $accounts->whereIn('user_id', $visibleIds);
            $payments->whereIn('account_phone', $actor->visibleAccountPhones());
        }

        return [
            'total_accounts' => (clone $accounts)->count(),
            'running_accounts' => (clone $accounts)->where('status', 'running')->count(),
            'booked_today' => $payments->count(),
        ];
    }

    /**
     * Assignable roles and the owners the client may parent a new user to. Only an admin
     * picks an owner, so the list is empty for everyone else — a manager or sub manager
     * always parents to themselves. Each owner carries its role so the UI can narrow the
     * choices to the ones User::parentRolesFor() would accept.
     */
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        return response()->json([
            'roles' => $actor->assignableRoles(),
            'managers' => $actor->isSuperAdmin()
                ? User::whereIn('role', User::OWNER_ROLES)->orderBy('name')->get(['id', 'name', 'email', 'role'])
                : [],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $data['plain_password'] = $data['password'];
        $data['password'] = Hash::make($data['password']);
        $data['is_approved'] = true;
        $data['approved_at'] = now();

        // An owner's new users always land in their own subtree: the role is already bounded
        // to something below them by validation, and the owner is themselves. An admin may
        // place the user under a chosen owner; roles that are never parented get none.
        if (! $actor->isSuperAdmin()) {
            $data['parent_id'] = $actor->id;
        } elseif (User::parentRolesFor($data['role']) === []) {
            $data['parent_id'] = null;
        }

        $user = User::create($data);

        return response()->json($this->present($request, $user), 201);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $user->approve();

        return response()->json($this->present($request, $user));
    }

    public function unapprove(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot unapprove your own account.'], 422);
        }

        Gate::authorize('update', $user);

        $user->update(['is_approved' => false, 'approved_at' => null]);

        return response()->json($this->present($request, $user));
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot reject your own account.'], 422);
        }

        Gate::authorize('delete', $user);

        $user->delete();

        return response()->json(null, 204);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        if (array_key_exists('password', $data)) {
            if ($data['password']) {
                $data['plain_password'] = $data['password'];
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
        }

        // Owners may rename or re-credential the users below them but never re-role or
        // re-home them, which would move someone out of (or somebody else into) their
        // subtree. Promoting to a role that is never parented drops the old owner.
        if (! $actor->isSuperAdmin()) {
            unset($data['role'], $data['parent_id']);
        } elseif (array_key_exists('role', $data) && User::parentRolesFor($data['role']) === []) {
            $data['parent_id'] = null;
        }

        $user->update($data);

        return response()->json($this->present($request, $user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        Gate::authorize('delete', $user);

        $user->delete();

        return response()->json(null, 204);
    }
}
