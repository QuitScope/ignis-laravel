<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailSignature;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\SignatureTemplate;

/**
 * The signature of the chosen mailbox (legacy signatureForm/saveSignature). Without an own one the editor shows the
 * default template with its placeholders; the preview fills them with the writer's details. Saving an empty one is a
 * decision ("none") and does not fall back to the default. Only mailboxes of MailboxAccess::accessible().
 */
class SignatureController extends Controller
{
    /** GET /mail/signature (?mailbox=) */
    public function edit(Request $request): Response|RedirectResponse
    {
        [$user, $mailbox] = $this->context($request, $request->query('mailbox'));
        if ($mailbox === null) {
            return redirect('/mail');
        }
        $own = MailSignature::query()->where('mailbox_id', $mailbox->id)->first();
        $template = $own !== null ? $own->body : SignatureTemplate::default();
        if (($template['content'] ?? []) === []) {
            $template = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        }

        return inertia('Mail/Signature', [
            'mailbox' => ['id' => $mailbox->id, 'address' => $mailbox->address, 'display_name' => $mailbox->display_name, 'kind' => $mailbox->kind],
            'body' => $template,
            'hasOwn' => $own !== null,
            'variables' => collect(SignatureTemplate::catalog())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'values' => SignatureTemplate::previewValues(SignatureTemplate::writer($mailbox, $user), $mailbox->address, $mailbox->display_name),
        ]);
    }

    /** PUT /mail/signature (mailbox_id, body_json) */
    public function update(Request $request): RedirectResponse
    {
        // Named explicitly: never the remembered mailbox instead (a stale choice must not get this signature).
        $request->validate(['mailbox_id' => ['required', 'integer']]);
        [, $mailbox] = $this->context($request, $request->input('mailbox_id'));
        abort_if($mailbox === null, 403); // a named mailbox the account may not open, never the own one instead
        try {
            $body = SignatureTemplate::parse($request->input('body_json'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body_json' => $e->getMessage()]);
        }
        $body = SignatureTemplate::hasContent($body) ? $body : ['type' => 'doc', 'content' => []];
        MailSignature::unguarded(fn () => MailSignature::query()->updateOrCreate(['mailbox_id' => $mailbox->id], ['body' => $body]));

        return redirect('/mail/signature?mailbox='.$mailbox->id)->with('status', 'mail.signature.saved');
    }

    /** @return array{User, ?Mailbox} */
    private function context(Request $request, mixed $requested): array
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission(['admin', 'mail.use']), 403);
        $id = is_int($requested) || (is_string($requested) && ctype_digit($requested)) ? (int) $requested : null;
        $mailbox = MailboxAccess::selected($user, $id);
        if ($mailbox !== null && $id !== null) {
            MailboxAccess::remember($mailbox);
        }

        return [$user, $mailbox];
    }
}
