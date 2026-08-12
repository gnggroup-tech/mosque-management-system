<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function approve(User $actor, User $account): bool
    {
        return $actor->can('users.approve') && ! $actor->is($account);
    }
}
