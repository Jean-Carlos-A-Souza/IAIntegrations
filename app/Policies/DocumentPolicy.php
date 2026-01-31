<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document);
    }

    private function ownsDocument(User $user, Document $document): bool
    {
        if ((int) $document->owner_user_id !== (int) $user->id) {
            return false;
        }

        if ($document->tenant_id === null && $user->tenant_id === null) {
            return true;
        }

        return (int) $document->tenant_id === (int) $user->tenant_id;
    }
}
