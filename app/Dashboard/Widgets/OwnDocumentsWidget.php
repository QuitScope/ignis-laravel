<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Documents\DocumentListing;
use App\Models\LegacyDocument;
use App\Models\User;
use stdClass;

/** The newest documents of the viewer's own record (legacy documents.php); the recipient rule and "drafts never" live in DocumentListing. */
final class OwnDocumentsWidget implements Widget
{
    public const ROWS = 5;

    public function key(): string
    {
        return 'own_documents';
    }

    public function area(): string
    {
        return 'main';
    }

    public function order(): int
    {
        return 30;
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    public function data(User $user): array
    {
        if ($user->personnel_id === null) {
            return ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => '/me/documents'];
        }

        $label = fn (stdClass $d) => $d->kind === 'legacy' ? LegacyDocument::labelFor((int) $d->type) : __('documents.kind.editor');

        return [
            'total' => DocumentListing::forRecipient($user, perPage: 1)->total(),
            'items' => array_map(fn (stdClass $d) => [
                'title' => filled($d->title) ? $d->title : $label($d),
                'type_label' => $label($d),
                'date' => $d->issued_on ? substr((string) $d->issued_on, 0, 10) : null,
                'href' => $d->kind === 'legacy' ? '/personnel/document-view?docid='.rawurlencode($d->public_id) : "/documents/{$d->id}",
            ], DocumentListing::recentFor($user, self::ROWS)),
            'href' => '/me/documents',
        ];
    }
}
