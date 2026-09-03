<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorises administration of user records across the four-tier hierarchy:
 * super_admin > manager > sub_manager > agent.
 *
 * The hard rule this enforces is lateral isolation: an owner may only ever see and act on
 * their own subtree — a manager reaches their sub managers and the agents beneath them, a
 * sub manager only their own agents, and neither ever reaches a peer or an admin. All of
 * the subtree logic lives in User::canManageUser() so the policy stays declarative.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    /**
     * A user may always view their own record; otherwise the subtree rule applies.
     */
    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->canManageUser($target);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->canManageUser($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->canManageUser($target);
    }

    /**
     * Signing in as another user. Deliberately the same subtree rule as update: a manager
     * can become one of their own agents, but never a peer manager or an admin.
     */
    public function impersonate(User $user, User $target): bool
    {
        return $user->canManageUser($target);
    }

    /**
     * Whether the target's stored plaintext password may be revealed. Same subtree rule as
     * viewing the record itself: a user may always see their own password, and otherwise
     * one manager can never read another manager's agents' passwords.
     */
    public function viewCredentials(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->canManageUser($target);
    }
}
