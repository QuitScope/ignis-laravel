<?php

namespace App\Documents;

use App\Models\Document;
use App\Models\Personnel;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The placeholders a template may use (`{{mitarbeiter.name}}`) and their values (legacy
 * VariableCatalog). Keys stay German: they are stored in templates and documents.
 * A value that cannot be resolved is missing from resolve(); missingReasons() says why.
 *
 * Gender: 1 female, 2 diverse (neutral forms, see NEUTRAL), anything else male as in
 * legacy. Ranks and qualifications use HasGenderedName::labelFor, whose neutral form is `name`.
 */
final class VariableCatalog
{
    public const NEUTRAL = ['anrede' => '', 'briefanrede' => 'Guten Tag', 'seine_ihre' => 'die', 'zum_zur' => 'als'];

    private const ORGANISATION = ['name' => 'name', 'art' => 'type', 'stadt' => 'city', 'strasse' => 'street', 'plz' => 'zip'];

    private const ISSUER_KEYS = ['name', 'titel', 'dienstgrad', 'zusatz'];

    /**
     * Key => label, in the order the editor offers them.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return [
            'mitarbeiter.name' => 'Mitarbeiter: Name mit Titel',
            'mitarbeiter.titel' => 'Mitarbeiter: Titel',
            'mitarbeiter.dienstgrad' => 'Mitarbeiter: Dienstgrad',
            'mitarbeiter.qualifikation_fw' => 'Mitarbeiter: Qualifikation Feuerwehr',
            'mitarbeiter.qualifikation_rd' => 'Mitarbeiter: Qualifikation Rettungsdienst',
            'mitarbeiter.dienstnummer' => 'Mitarbeiter: Dienstnummer',
            'mitarbeiter.geburtsdatum' => 'Mitarbeiter: Geburtsdatum',
            'mitarbeiter.eintritt' => 'Mitarbeiter: Eintrittsdatum',
            'mitarbeiter.anrede' => 'Anrede (Herr/Frau)',
            'mitarbeiter.briefanrede' => 'Briefanrede (Sehr geehrter Herr …)',
            'mitarbeiter.ihm_ihr' => 'ihm / ihr',
            'mitarbeiter.seine_ihre' => 'seine / ihre',
            'mitarbeiter.zum_zur' => 'zum / zur',
            'aussteller.name' => 'Aussteller: Name mit Titel',
            'aussteller.titel' => 'Aussteller: Titel',
            'aussteller.dienstgrad' => 'Aussteller: Dienstgrad',
            'aussteller.zusatz' => 'Aussteller: Zusatz',
            'organisation.name' => 'Organisation: Name',
            'organisation.art' => 'Organisation: Art',
            'organisation.stadt' => 'Organisation: Stadt',
            'organisation.strasse' => 'Organisation: Straße',
            'organisation.plz' => 'Organisation: PLZ',
            'dokument.kennung' => 'Dokument: Kennung',
            'datum' => 'Datum (heute)',
        ];
    }

    /**
     * Values for the document's personnel record, the signed-in account's record (issuer), the
     * organisation settings, the document id and today's date.
     *
     * @param  Document|array{personnel?: ?Personnel, public_id?: ?string}  $context
     * @return array<string, string>
     */
    public static function resolve(Document|array $context = []): array
    {
        [$personnel, $publicId] = self::context($context);
        $values = $personnel !== null ? self::person($personnel, 'mitarbeiter') : [];

        $issuer = self::issuer();
        if ($issuer !== null) {
            $values += array_intersect_key(self::person($issuer, 'aussteller'), array_flip(array_map(fn ($k) => 'aussteller.'.$k, self::ISSUER_KEYS)));
        }

        $settings = app(Settings::class);
        foreach (self::ORGANISATION as $key => $setting) {
            $value = $settings->get('organisation.'.$setting);
            if (is_string($value) && $value !== '') {
                $values['organisation.'.$key] = $value;
            }
        }

        if ($publicId !== null && $publicId !== '') {
            $values['dokument.kennung'] = $publicId;
        }
        $values['datum'] = Carbon::now()->format('d.m.Y');

        return array_intersect_key(array_replace(self::catalog(), $values), $values, self::catalog());
    }

    /**
     * Why a key stays empty, one sentence per unresolved key for the writer.
     *
     * @param  Document|array{personnel?: ?Personnel, public_id?: ?string}  $context
     * @return array<string, string>
     */
    public static function missingReasons(Document|array $context = []): array
    {
        $resolved = self::resolve($context);
        $personnel = self::context($context)[0];
        $issuer = self::issuer();

        $reasons = [];
        foreach (array_keys(array_diff_key(self::catalog(), $resolved)) as $key) {
            [$group, $field] = array_pad(explode('.', $key, 2), 2, '');
            $reasons[$key] = match ($group) {
                'mitarbeiter' => $personnel !== null ? self::personReason($field, 'Beim Mitarbeiter') : 'Das Dokument hängt an keinem Mitarbeiter.',
                'aussteller' => $issuer !== null ? self::personReason($field, 'In deinem Mitarbeiterdatensatz')
                    : 'Dein Benutzerkonto ist mit keinem Mitarbeiter verknüpft. Das erledigt die Personalverwaltung.',
                'organisation' => 'In den Einstellungen der Organisation ist dazu nichts eingetragen.',
                'dokument' => 'Das Dokument hat noch keine Kennung.',
                default => 'Dafür ist kein Wert erfasst.',
            };
        }

        return $reasons;
    }

    /**
     * @param  Document|array{personnel?: ?Personnel, public_id?: ?string}  $context
     * @return array{0: ?Personnel, 1: ?string}
     */
    private static function context(Document|array $context): array
    {
        return $context instanceof Document
            ? [$context->personnel, $context->public_id]
            : [$context['personnel'] ?? null, $context['public_id'] ?? null];
    }

    private static function issuer(): ?Personnel
    {
        $user = Auth::user();

        return $user instanceof User ? $user->personnel : null;
    }

    /** @return array<string, string> */
    private static function person(Personnel $p, string $prefix): array
    {
        $gender = $p->gender;
        $forms = match ($gender) {
            1 => ['anrede' => 'Frau', 'briefanrede' => 'Sehr geehrte Frau', 'ihm_ihr' => 'ihr', 'seine_ihre' => 'ihre', 'zum_zur' => 'zur'],
            2 => self::NEUTRAL + ['ihm_ihr' => $p->displayName()],
            default => ['anrede' => 'Herr', 'briefanrede' => 'Sehr geehrter Herr', 'ihm_ihr' => 'ihm', 'seine_ihre' => 'seine', 'zum_zur' => 'zum'],
        };

        $values = [
            'name' => $p->displayName(),
            'titel' => $p->title?->name,
            'dienstgrad' => $p->rank->labelFor($gender),
            'qualifikation_fw' => $p->fireQualification?->labelFor($gender),
            'qualifikation_rd' => $p->emsQualification?->labelFor($gender),
            'dienstnummer' => $p->service_number,
            'geburtsdatum' => $p->birth_date->format('d.m.Y'),
            'eintritt' => $p->hired_at->format('d.m.Y'),
            'zusatz' => $p->extra_qualification,
        ] + $forms;

        $out = [];
        foreach ($values as $key => $value) {
            if ($value !== null && ($value !== '' || $key === 'anrede')) {
                $out[$prefix.'.'.$key] = $value;
            }
        }

        return $out;
    }

    private static function personReason(string $field, string $where): string
    {
        return $where.' '.match ($field) {
            'titel' => 'ist kein Titel eingetragen.',
            'dienstgrad' => 'ist kein Dienstgrad eingetragen.',
            'qualifikation_fw' => 'ist keine Qualifikation Feuerwehr eingetragen.',
            'qualifikation_rd' => 'ist keine Qualifikation Rettungsdienst eingetragen.',
            'dienstnummer' => 'ist keine Dienstnummer eingetragen.',
            'zusatz' => 'ist kein Zusatz eingetragen.',
            default => 'fehlt dieser Wert.',
        };
    }
}
