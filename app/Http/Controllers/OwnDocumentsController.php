<?php

namespace App\Http\Controllers;

use App\Documents\DocumentListing;
use App\Models\LegacyDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/** `GET /me/documents` (legacy OwnListsController::documents): the documents of the signed-in account's own record. */
class OwnDocumentsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : '';
        $sort = $request->query('sort');
        $sort = is_string($sort) ? $sort : 'ausgestellt';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Documents/Mine', [
            'documents' => DocumentListing::forRecipient($request->user(), mb_substr($q, 0, 100), $sort, $dir)
                ->withQueryString()
                ->through(fn (stdClass $d) => [
                    'kind' => $d->kind,
                    'public_id' => $d->public_id,
                    'type' => $d->type === null ? null : (int) $d->type,
                    'type_label' => $d->type === null ? null : LegacyDocument::labelFor((int) $d->type),
                    'title' => $d->title,
                    'issuer' => $d->issuer,
                    'issued_on' => $d->issued_on ? substr((string) $d->issued_on, 0, 10) : null,
                    'url' => $d->kind === 'legacy' ? '/personnel/document-view?docid='.rawurlencode($d->public_id) : "/documents/{$d->id}",
                ]),
            'filters' => ['q' => $q, 'sort' => $sort, 'dir' => $dir],
        ]);
    }
}
