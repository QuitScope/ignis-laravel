<?php

namespace Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Support\AddressGenerator;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\SignatureTemplate;

/**
 * GET|PUT /settings/mail (mail.admin; legacy MailAdminController::settings/saveSettings): default domain, address
 * pattern, further allowed domains, send pause (0-3600 s) and the default signature. The values are `editable: false`,
 * so only this page writes them, each one checked. The default domain changes only with mail.domain.choose (a posted
 * value is ignored otherwise). The audit names the changed keys with old and new values, the signature only as changed.
 */
class MailSettingsController extends Controller
{
    public const MAX_SEND_COOLDOWN = 3600;

    public function edit(Request $request, Settings $settings): Response
    {
        /** @var User $user */
        $user = $request->user();
        $own = Mailbox::query()->where('user_id', $user->id)->first();

        return Inertia::render('Mail/Admin/Settings', [
            'settings' => $this->current($settings),
            'patterns' => AddressGenerator::PATTERNS,
            'canChooseDomain' => $user->can('chooseDomain', Mailbox::class),
            'signature' => SignatureTemplate::default(),
            'variables' => collect(SignatureTemplate::catalog())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'values' => SignatureTemplate::previewValues($own->personnel ?? $user->personnel, $own->address ?? '', $own->display_name ?? ''),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $canChoose = $user->can('chooseDomain', Mailbox::class);
        $data = $request->validate([
            'domain' => $canChoose ? ['required', 'string', 'max:100'] : ['nullable'],
            'address_pattern' => ['required', 'string', Rule::in(AddressGenerator::PATTERNS)],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'send_cooldown' => ['required', 'regex:/^\d{1,4}$/', 'integer', 'between:0,'.self::MAX_SEND_COOLDOWN],
            'signature' => ['required', 'string'],
        ]);

        $current = $this->current($settings);
        $domain = $canChoose ? AddressRules::normalize($data['domain']) : $current['domain'];
        if (! AddressRules::isDomain($domain)) {
            throw ValidationException::withMessages(['domain' => __('mail.settings.invalid_domain')]);
        }
        $entries = preg_split('/[\s,;]+/', (string) ($data['allowed_domains'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $invalid = array_filter($entries, fn (string $d) => ! AddressRules::isDomain(AddressRules::normalize($d)));
        if ($invalid !== []) {
            throw ValidationException::withMessages(['allowed_domains' => __('mail.settings.invalid_domains', ['domains' => implode(', ', $invalid)])]);
        }
        try {
            $signature = SignatureTemplate::parse($data['signature']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['signature' => $e->getMessage()]);
        }

        $values = [
            'mail.domain' => $domain,
            'mail.address_pattern' => $data['address_pattern'],
            'mail.allowed_domains' => array_values(array_diff(AddressRules::parseDomains($entries), [$domain])),
            'mail.send_cooldown' => (int) $data['send_cooldown'],
        ];
        $before = [
            'mail.domain' => $current['domain'], 'mail.address_pattern' => $current['address_pattern'],
            'mail.allowed_domains' => $this->allowed($settings), 'mail.send_cooldown' => $current['send_cooldown'],
        ];
        $changed = [];
        foreach ($values as $key => $value) {
            if ($value !== $before[$key]) {
                $changed[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }
        $signatureChanged = $signature !== SignatureTemplate::parse((string) json_encode(SignatureTemplate::default()));
        if ($signatureChanged) {
            $changed['mail.signature'] = 'changed';
        }
        if ($changed === []) {
            return redirect('/settings/mail')->with('status', 'mail.admin.unchanged');
        }

        DB::transaction(function () use ($settings, $values, $changed, $signature, $signatureChanged) {
            foreach (array_intersect_key($values, $changed) as $key => $value) {
                $settings->set($key, $value);
            }
            if ($signatureChanged) {
                SignatureTemplate::saveDefault($signature);
            }
        });
        AuditLogger::log($user, 'Mail-Einstellungen geändert', implode(', ', array_keys($changed)), 'Mail', true, $changed);

        return redirect('/settings/mail')->with('status', 'mail.settings.saved');
    }

    /** @return array{domain: string, address_pattern: string, allowed_domains: string, send_cooldown: int} */
    private function current(Settings $settings): array
    {
        $pattern = (string) $settings->get('mail.address_pattern', 'initial_dot_last');

        return [
            'domain' => AddressRules::defaultDomain(),
            'address_pattern' => in_array($pattern, AddressGenerator::PATTERNS, true) ? $pattern : 'initial_dot_last',
            'allowed_domains' => implode(', ', $this->allowed($settings)),
            'send_cooldown' => (int) $settings->get('mail.send_cooldown', 10),
        ];
    }

    /** @return list<string> the further allowed domains as stored (valid ones, without the default) */
    private function allowed(Settings $settings): array
    {
        $stored = $settings->get('mail.allowed_domains', []);

        return array_values(array_diff(AddressRules::parseDomains(is_array($stored) ? $stored : []), [AddressRules::defaultDomain()]));
    }
}
