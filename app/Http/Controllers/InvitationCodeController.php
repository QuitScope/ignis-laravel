<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Models\RegistrationCode;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InvitationCodeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('createRegistrationCode', User::class);

        $codes = RegistrationCode::query()->with(['creator', 'usedByUser', 'personnel:id,full_name'])->latest('id')->get()
            ->map(fn (RegistrationCode $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'url' => $c->url(),
                'personnel' => $c->personnel?->full_name,
                'status' => $c->used_at !== null ? 'used' : ($c->isRedeemable() ? 'unused' : 'expired'),
                'used_by' => $c->usedByUser?->username,
                'created_by' => $c->creator?->username,
                'expires_at' => $c->expires_at?->toDateString(),
            ]);

        return Inertia::render('Users/Invitations', [
            'codes' => $codes,
            'freePersonnel' => AccountLink::freePersonnel()->map->only(['id', 'full_name', 'service_number'])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('createRegistrationCode', User::class);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
            'personnel_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $personnelId = isset($data['personnel_id']) ? (int) $data['personnel_id'] : null;
        if ($personnelId !== null && ! Personnel::query()->whereKey($personnelId)->whereDoesntHave('user')->exists()) {
            throw ValidationException::withMessages(['personnel_id' => __('account_link.not_free')]);
        }

        $code = RegistrationCode::generate(
            $request->user(),
            ($data['label'] ?? '') === '' ? null : $data['label'],
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        );
        $context = ['registration_code_id' => $code->id];
        if ($personnelId !== null) {
            $code->personnel_id = $personnelId;
            $code->save();
            $context['personnel_id'] = $personnelId;
        }
        AuditLogger::log($request->user(), 'Einladung erstellt', $code->label, 'Einladungen', true, $context);

        return back();
    }

    public function destroy(RegistrationCode $code): RedirectResponse
    {
        $this->authorize('createRegistrationCode', User::class);
        if (RegistrationCode::query()->whereKey($code->id)->whereNull('used_at')->delete() > 0) {
            AuditLogger::log(request()->user(), 'Einladung gelöscht', $code->label, 'Einladungen', true, ['registration_code_id' => $code->id]);
        }

        return back();
    }
}
