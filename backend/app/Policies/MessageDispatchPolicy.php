<?php

namespace App\Policies;

use App\Models\MessageDispatch;
use App\Models\User;

class MessageDispatchPolicy
{
    public function view(User $user, MessageDispatch $dispatch): bool
    {
        if (($user->role ?? null) === 'superadmin') {
            return true;
        }

        return $dispatch->tenant_id === $user->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null || ($user->role ?? null) === 'superadmin';
    }
}
