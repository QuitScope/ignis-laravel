<?php

namespace App\Search\Sources;

use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\User;
use App\Search\SearchSource;
use App\Support\SqlLike;
use Illuminate\Support\Facades\DB;

/**
 * Editor and legacy documents, each row only if the viewer may open it:
 *  - editor documents (DocumentPolicy::open): drafts with document.manage; issued ones with document.view or as the
 *    recipient (the account linked to the record);
 *  - legacy documents, never archived ones (legacy DocumentSource): all with document.view, else the recipient's
 *    and the issuer's own (LegacyDocumentController::show).
 * Editor hits come first, the legacy ones fill up to the limit (legacy order).
 */
final class DocumentSource implements SearchSource
{
    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return __('search.source.documents');
    }

    public function allowed(User $user): bool
    {
        return $user->can('document.view') || $user->personnel_id !== null
            || LegacyDocument::where('issuer_user_id', $user->id)->where('is_archived', false)->exists();
    }

    public function search(User $user, string $q, int $limit): array
    {
        $view = $user->can('document.view');
        $own = $user->personnel_id ?? 0;

        $editor = DB::table('documents as d')->join('personnel as p', 'p.id', '=', 'd.personnel_id')
            ->select('d.id', 'd.public_id', 'd.title', 'd.status', 'p.full_name');
        SqlLike::anyOf($editor, ['d.title', 'd.public_id', 'p.full_name'], $q);
        if (! $user->can('document.manage')) {
            $editor->where('d.status', Document::ISSUED);
        }
        if (! $view) {
            $editor->where('d.personnel_id', $own);
        }
        $items = $editor->orderByDesc('d.created_at')->orderByDesc('d.id')->limit($limit)->get()->map(fn (object $d) => [
            'label' => (string) $d->title,
            'sub' => implode(' · ', [(string) $d->full_name, '#'.$d->public_id, __('documents.status.'.($d->status === Document::ISSUED ? 'issued' : 'draft'))]),
            'href' => "/documents/{$d->id}",
        ])->all();

        $remaining = $limit - count($items);
        if ($remaining <= 0) {
            return $items;
        }
        $legacy = LegacyDocument::query()->select(['public_id', 'recipient_name', 'type', 'issued_on'])->where('is_archived', false);
        SqlLike::anyOf($legacy, ['recipient_name', 'public_id', 'issuer_name'], $q);
        if (! $view) {
            $legacy->where(fn ($w) => $w->where('personnel_id', $own)->orWhere('issuer_user_id', $user->id));
        }
        foreach ($legacy->orderByDesc('created_at')->orderByDesc('id')->limit($remaining)->get() as $d) {
            $items[] = [
                'label' => $d->recipient_name ?: __('search.legacy_document', ['id' => $d->public_id]),
                'sub' => implode(' · ', array_filter([$d->typeLabel(), (string) $d->issued_on?->format('d.m.Y')], fn ($s) => $s !== '')),
                'href' => '/personnel/document-view?docid='.rawurlencode($d->public_id),
            ];
        }

        return $items;
    }

    /**
     * Titles of issued editor documents and recipients of legacy ones that are not archived: exactly the rows every
     * document.view holder sees. Never drafts (manage only), archived rows or ids.
     */
    public function vocabulary(): array
    {
        return [
            ...Document::query()->where('status', Document::ISSUED)->distinct()->limit(5000)->pluck('title')->all(),
            ...LegacyDocument::query()->where('is_archived', false)->whereNotNull('recipient_name')->distinct()->limit(5000)->pluck('recipient_name')->all(),
        ];
    }

    /** Readers only: a recipient or issuer sees just their own rows, so a corrected query could hint at foreign ones. */
    public function fuzzyAllowed(User $user): bool
    {
        return $user->can('document.view');
    }
}
