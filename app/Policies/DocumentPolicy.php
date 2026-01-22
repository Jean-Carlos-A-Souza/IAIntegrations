<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function delete(User $user, Document $document): bool
    {
        return in_array($user->role, ['owner', 'admin'], true);
    }
}
