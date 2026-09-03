<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\AccountLockWindow;
use Illuminate\Auth\Access\Response;

class AccountPolicy
{
    /**
     * Whether the account belongs to someone inside the user's visibility scope: the user
     * themselves for an agent, the user plus their agents for a manager, anyone for an admin.
     */
    private function inScope(User $user, Account $account): bool
    {
        $visibleIds = $user->visibleUserIds();

        return $visibleIds === null || in_array($account->user_id, $visibleIds, true);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('accounts.read');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Account $account): bool
    {
        return $user->hasPermission('accounts.read') && $this->inScope($user, $account);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('accounts.write');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Account $account): Response|bool
    {
        if (! $user->hasPermission('accounts.write') || ! $this->inScope($user, $account)) {
            return false;
        }

        return $this->notLocked($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Account $account): Response|bool
    {
        if (! $user->hasPermission('accounts.write') || ! $this->inScope($user, $account)) {
            return false;
        }

        return $this->notLocked($user);
    }

    /**
     * Agents cannot edit or delete accounts inside the daily lock window; managers and admins can.
     * Denied with an explicit message so the caller sees why rather than a bare 403.
     */
    private function notLocked(User $user): Response
    {
        return AccountLockWindow::blocks($user)
            ? Response::deny(AccountLockWindow::MESSAGE)
            : Response::allow();
    }
}
