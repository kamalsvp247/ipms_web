<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $actor = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', Password::defaults()],
            // Bounded by the actor's own rank: a manager may mint sub managers and agents,
            // a sub manager only agents, and neither can ever create a peer.
            'role' => ['required', 'string', Rule::in($actor->assignableRoles())],
            'parent_id' => $this->parentRules($actor, (string) $this->input('role')),
        ];
    }

    /**
     * Rules for the owner a new user hangs off.
     *
     * Only an admin picks an owner at all — everyone else always parents to themselves, so
     * the field is rejected outright. For an admin the allowed owners follow the role being
     * created: a sub manager must name a manager, an agent may name either owner tier or
     * stay admin-owned, and a manager or admin may not be parented at all.
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

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'role.in' => 'You are not allowed to assign that role.',
            'parent_id.exists' => 'The selected manager cannot own a user with that role.',
            'parent_id.required' => 'A sub manager must be placed under a manager.',
            'parent_id.prohibited' => 'You cannot choose the owning manager.',
        ];
    }
}
