<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Legacy DocumentPolicy abilities, plus the recipient rule and the delete
 * rule. Registered as the Document policy and under the `document.*` gate names.
 */
class DocumentPolicy
{
    public function view(User $user, ?Document $document = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.documents.manage', 'personnel.documents.view']);
    }

    public function manage(User $user, ?Document $document = null): bool
    {
        return $user->hasPermission(['admin', 'personnel.documents.manage']);
    }

    public function viewAudit(User $user): bool
    {
        return $user->hasPermission(['admin']);
    }

    public function viewIssued(User $user, Document $document): bool
    {
        return $document->status === Document::ISSUED && $user->personnel_id !== null && (int) $user->personnel_id === (int) $document->personnel_id;
    }

    public function open(User $user, Document $document): bool
    {
        return $document->status === Document::ISSUED
            ? $this->view($user) || $this->viewIssued($user, $document)
            : $this->manage($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->status === Document::ISSUED ? $user->hasPermission(['admin']) : $this->manage($user);
    }
}
