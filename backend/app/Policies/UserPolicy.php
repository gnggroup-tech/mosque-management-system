<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function approve(User $actor, User $account): bool
    {
        return $actor->can('users.approve') && ! $actor->is($account);
    }

    public function suspend(User $actor, User $account): bool
    {
        return $actor->can('users.suspend') && ! $actor->is($account);
    }

    public function reactivate(User $actor, User $account): bool
    {
        return $actor->can('users.reactivate') && ! $actor->is($account);
    }

    public function archive(User $actor, User $account): bool
    {
        return $actor->can('users.archive') && ! $actor->is($account);
    }
}
