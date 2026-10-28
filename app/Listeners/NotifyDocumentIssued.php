<?php

namespace App\Listeners;

use App\Events\DocumentIssued;
use App\Models\User;
use App\Notifications\InboxNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The account linked to the document's record learns about it (legacy `dokument` notification), unless
 * it issued the document itself. The event fires after the commit. A failure is reported, never fails the issue.
 * The text is stored in the locale of the request that sent it (the install-wide locale today).
 */
class NotifyDocumentIssued
{
    public function handle(DocumentIssued $event): void
    {
        $doc = $event->document;
        rescue(function () use ($doc) {
            $recipients = User::active()->where('personnel_id', $doc->personnel_id)
                ->when($doc->issued_by !== null, fn ($q) => $q->whereKeyNot($doc->issued_by))->get();
            Notification::send($recipients, new InboxNotification(
                'dokument',
                trans('notifications.document.title'),
                trans('notifications.document.message', ['title' => $doc->title, 'number' => $doc->public_id]),
                "/documents/{$doc->id}",
            ));
        });
    }
}
