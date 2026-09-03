<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $actor = $this->user();
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', "unique:users,email,{$userId}"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
            // Re-roling and re-homing are admin-only; the controller also strips both for
            // non-admins so a crafted request cannot move a user between owners.
            'role' => ['sometimes', 'string', Rule::in($actor->assignableRoles())],
            'parent_id' => ['sometimes', ...$this->parentRules($actor, $this->effectiveRole())],
        ];
    }

    /**
     * The role the target ends up with: the one being assigned, or its current one when the
     * payload leaves it alone.
     */
    private function effectiveRole(): string
    {
        return (string) ($this->input('role') ?? $this->route('user')?->role);
    }

    /**
     * Rules for the owner this user hangs off. Mirrors StoreUserRequest: admin-only, and the
     * allowed owners follow the effective role.
     *
     * @return list<mixed>
     */
    private function parentRules(User $actor, string $role): array
    {
        $parentRoles = User::parentRolesFor($role);

        if (! $actor->isSuperAdmin() || $parentRoles === []) {
            return ['prohibited'];
        }

        return [
            User::requiresParent($role) ? 'required' : 'nullable',
            Rule::exists('users', 'id')->whereIn('role', $parentRoles),
        ];
    }

    /**
     * Guards the pairing the field rules cannot see on their own: promoting someone to sub
     * manager while leaving their existing owner alone must still land them under a manager,
     * and nobody may become their own owner.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');

                if (! $target || ! $this->user()->isSuperAdmin() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $role = $this->effectiveRole();
                $parentId = $this->has('parent_id') ? $this->input('parent_id') : $target->parent_id;

                if ($parentId !== null && (int) $parentId === $target->id) {
                    $validator->errors()->add('parent_id', 'A user cannot be their own manager.');

                    return;
                }

                if ($parentId === null && User::requiresParent($role)) {
                    $validator->errors()->add('parent_id', 'A sub manager must be placed under a manager.');

                    return;
                }

                $parentRoles = User::parentRolesFor($role);

                if ($parentId !== null && ! User::whereKey($parentId)->whereIn('role', $parentRoles)->exists()) {
                    $validator->errors()->add('parent_id', 'The current manager cannot own a user with that role.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'role.in' => 'You are not allowed to assign that role.',
            'parent_id.exists' => 'The selected manager cannot own a user with that role.',
            'parent_id.required' => 'A sub manager must be placed under a manager.',
            'parent_id.prohibited' => 'You cannot change the owning manager.',
        ];
    }
}
