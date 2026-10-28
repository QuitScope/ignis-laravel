<?php

namespace App\Documents;

use App\Documents\Nodes\DocField;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Rebuilds a posted document along its (current) template, so the browser can only change what the
 * template leaves open:
 * - order, mode, title and ids of the sections come from the template; posted sections that match no
 *   template section, or content outside sections, are dropped;
 * - a locked section is the template's content; only its field values come from the post, checked
 *   against the template's field (options, maxLength);
 * - a free section keeps the posted content, but a field in it keeps the template's definition and
 *   only takes its value from the post; fields the template section does not have are dropped;
 * - a repeatable section may appear several times (instances carry templateSectionId = group), in
 *   posted order; any other section exactly once, a missing one comes back from the template.
 * Every correction is reported as a warning (plain text, naming section and field; escape on output),
 * none when the post already equals the result. The result passes DocumentValidator.
 */
final class SectionGuard
{
    private const DEFAULTS = [
        'orderedList' => ['start' => 1],
        'tableCell' => ['colspan' => 1, 'rowspan' => 1],
        'tableHeader' => ['colspan' => 1, 'rowspan' => 1],
    ];

    private const PROPERTIES = ['title', 'hint', 'repeatable', 'templateSectionId'];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  array<mixed>  $templateDoc
     * @param  array<mixed>  $postedDoc
     *
     * @throws InvalidArgumentException the template itself is broken
     * @throws InvalidDocument the posted free content is not allowed
     */
    public function restoreLockedSections(array $templateDoc, array $postedDoc): GuardResult
    {
        try {
            $template = DocumentValidator::validate($templateDoc, true);
        } catch (InvalidDocument $e) {
            throw new InvalidArgumentException('Broken document template: '.$e->getMessage(), 0, $e);
        }
        $this->warnings = [];

        $groups = [];
        $reserved = [];
        foreach ($template['content'] as $section) {
            $groups[$section['attrs']['sectionId']] = $groups[self::group($section)] = self::group($section);
            $reserved[$section['attrs']['sectionId']] = true;
        }

        $instances = [];
        $postedIds = [];
        $posted = is_array($postedDoc['content'] ?? null) ? $postedDoc['content'] : [];
        foreach ($posted as $node) {
            $attrs = is_array($node) && ($node['type'] ?? null) === 'docSection' && is_array($node['attrs'] ?? null) ? $node['attrs'] : null;
            if ($attrs === null) {
                $this->warn('Inhalt außerhalb eines Abschnitts wurde verworfen.');

                continue;
            }
            if (is_string($attrs['sectionId'] ?? null)) {
                $postedIds[] = $attrs['sectionId'];
            }
            $group = self::lookup($groups, $attrs['templateSectionId'] ?? null) ?? self::lookup($groups, $attrs['sectionId'] ?? null);
            if ($group === null) {
                $title = is_string($attrs['title'] ?? null) && $attrs['title'] !== '' ? ' („'.DocumentValidator::short($attrs['title']).'“)' : '';
                $this->warn('Ein Abschnitt, den die Vorlage nicht kennt'.$title.', wurde verworfen.');

                continue;
            }
            $instances[$group][] = $node;
        }

        $content = [];
        $used = [];
        foreach ($template['content'] as $index => $section) {
            $name = 'Abschnitt '.($index + 1).(isset($section['attrs']['title']) ? ' „'.$section['attrs']['title'].'“' : '');
            $repeatable = ($section['attrs']['repeatable'] ?? false) === true;
            $mine = $instances[self::group($section)] ?? [];

            if ($mine === []) {
                $this->warn($name.' fehlte und kommt aus der Vorlage.');
                $mine = [null];
            } elseif (! $repeatable && count($mine) > 1) {
                $this->warn($name.' ist nicht wiederholbar, weitere Exemplare wurden verworfen.');
                $mine = [$mine[0]];
            }

            foreach ($mine as $instance) {
                $id = $section['attrs']['sectionId'];
                $postedAttrs = $instance === null ? null : self::normalise($instance['attrs']);
                $postedId = $postedAttrs['sectionId'] ?? null;
                if ($repeatable && $instance !== null) {
                    $free = is_string($postedId) && preg_match(DocumentValidator::ID_PATTERN, $postedId) === 1
                        && ! isset($used[$postedId]) && (! isset($reserved[$postedId]) || $postedId === $id);
                    $id = $free ? $postedId : (string) Str::uuid();
                    if (! $free) {
                        $this->warn($name.' hat eine neue Kennung bekommen.');
                    }
                } elseif ($instance !== null && $postedId !== $id) {
                    $this->warn($name.': Kennung wurde aus der Vorlage übernommen.');
                }
                $used[$id] = true;

                $attrs = $section['attrs'];
                $attrs['sectionId'] = $id;
                if ($id !== $section['attrs']['sectionId']) {
                    $attrs['templateSectionId'] = self::group($section);
                }
                if ($postedAttrs !== null) {
                    $this->compareAttrs($name, $postedAttrs, $attrs);
                }

                $content[] = ['type' => 'docSection', 'attrs' => $attrs, 'content' => $this->content($name, $section, $instance)];
            }
        }

        $outIds = array_map(fn (array $s) => $s['attrs']['sectionId'], $content);
        $postedOrder = array_values(array_unique(array_intersect($postedIds, $outIds)));
        if ($postedOrder !== array_values(array_intersect($outIds, $postedOrder))) {
            $this->warn('Die Reihenfolge der Abschnitte wurde aus der Vorlage wiederhergestellt.');
        }

        return new GuardResult(DocumentValidator::validate(['type' => 'doc', 'content' => $content], false), $this->warnings);
    }

    /**
     * @param  array<string, mixed>  $posted  normalised
     * @param  array<string, mixed>  $attrs  the result
     */
    private function compareAttrs(string $name, array $posted, array $attrs): void
    {
        if (($posted['mode'] ?? null) !== $attrs['mode']) {
            $this->warn($name.': Modus wurde auf „'.($attrs['mode'] === 'locked' ? 'gesperrt' : 'frei').'“ zurückgesetzt.');
        }

        $attrs = self::normalise($attrs);
        $changed = array_filter(self::PROPERTIES, fn (string $k) => ($posted[$k] ?? null) !== ($attrs[$k] ?? null));
        if ($changed !== []) {
            $this->warn($name.': Eigenschaften ('.implode(', ', $changed).') wurden aus der Vorlage übernommen.');
        }
    }

    /**
     * @param  array<string, mixed>  $section  from the template
     * @param  ?array<mixed>  $instance  posted, null when missing
     * @return list<array<mixed>>
     */
    private function content(string $name, array $section, ?array $instance): array
    {
        $posted = $instance !== null && is_array($instance['content'] ?? null) && $instance['content'] !== [] ? $instance['content'] : null;

        $definitions = [];
        foreach ($this->fields($section['content'] ?? []) as $field) {
            $definitions[$field['fieldId']] ??= $field;
        }

        if ($section['attrs']['mode'] === 'free') {
            return $posted === null ? $section['content'] ?? [] : $this->freeFields($name, $posted, $definitions);
        }

        if ($instance !== null && self::canonical($posted ?? []) !== self::canonical($section['content'] ?? [])) {
            $this->warn($name.': gesperrter Inhalt wurde aus der Vorlage wiederhergestellt.');
        }

        $values = [];
        foreach ($this->fields($posted ?? []) as $field) {
            $values[$field['fieldId']] ??= $field['value'] ?? '';
        }

        return $this->lockedFields($name, $section['content'] ?? [], $values, $instance !== null);
    }

    /**
     * Template content with each field's value taken from the post.
     *
     * @param  array<mixed>  $nodes
     * @param  array<string, mixed>  $values  posted value by fieldId
     * @return list<array<mixed>>
     */
    private function lockedFields(string $name, array $nodes, array $values, bool $posted): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'docField') {
                $value = $posted ? ($values[$node['attrs']['fieldId']] ?? '') : ($node['attrs']['value'] ?? '');
                $node['attrs']['value'] = $this->cleanValue($name, $node['attrs'], $value);
            } elseif (isset($node['content'])) {
                $node['content'] = $this->lockedFields($name, $node['content'], $values, $posted);
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * Posted free content; each field gets the template's definition, unknown fields go.
     *
     * @param  array<mixed>  $nodes
     * @param  array<string, array<string, mixed>>  $definitions  template field attrs by fieldId
     * @return list<mixed>
     */
    private function freeFields(string $name, array $nodes, array $definitions): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'docField') {
                $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
                $fieldId = is_string($attrs['fieldId'] ?? null) ? $attrs['fieldId'] : '';
                $definition = $definitions[$fieldId] ?? null;
                if ($definition === null) {
                    $label = is_string($attrs['label'] ?? null) && $attrs['label'] !== '' ? $attrs['label'] : $fieldId;
                    $this->warn($name.': Feld „'.DocumentValidator::short($label).'“ kennt die Vorlage nicht, es wurde entfernt.');

                    continue;
                }
                unset($attrs['value']);
                if (self::canonical($attrs) !== self::canonical(array_diff_key($definition, ['value' => 0]))) {
                    $this->warn($name.': Feld „'.self::label($definition).'“: Einstellungen wurden aus der Vorlage übernommen.');
                }
                $definition['value'] = $this->cleanValue($name, $definition, $node['attrs']['value'] ?? '');
                $node['attrs'] = $definition;
            } elseif (is_array($node) && is_array($node['content'] ?? null)) {
                $node['content'] = $this->freeFields($name, $node['content'], $definitions);
            }
            $out[] = $node;
        }

        return $out;
    }

    /** @param array<string, mixed> $field the field's (template) attrs */
    private function cleanValue(string $name, array $field, mixed $value): string
    {
        $clean = DocField::clean($field, $value);
        $where = $name.': Feld „'.self::label($field).'“: ';
        if (! is_string($value)) {
            $this->warn($where.'der Wert war ungültig und wurde geleert.');
        } elseif ($clean === '' && $value !== '') {
            $this->warn($where.'der Wert steht nicht in der Auswahl und wurde geleert.');
        } elseif ($clean !== $value) {
            $this->warn($where.'der Wert wurde auf '.$field['maxLength'].' Zeichen gekürzt.');
        }

        return $clean;
    }

    /**
     * Attributes of the fields that carry a string fieldId, in document order.
     *
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function fields(array $nodes, int $depth = 0): array
    {
        $found = [];
        foreach ($depth <= DocumentValidator::MAX_DEPTH ? $nodes : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'docField' && is_string($node['attrs']['fieldId'] ?? null)) {
                $found[] = $node['attrs'];
            } elseif (is_array($node['content'] ?? null)) {
                array_push($found, ...$this->fields($node['content'], $depth + 1));
            }
        }

        return $found;
    }

    private function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @param array<string, mixed> $field */
    private static function label(array $field): string
    {
        return ($field['label'] ?? '') !== '' ? $field['label'] : $field['fieldId'];
    }

    /**
     * Section attrs without the values the editor sends for "not set" (null, '', [], false).
     *
     * @return array<string, mixed>
     */
    private static function normalise(mixed $attrs): array
    {
        return is_array($attrs) ? array_filter($attrs, fn ($v) => ! in_array($v, [null, '', [], false], true)) : [];
    }

    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value) || $depth > 2 * DocumentValidator::MAX_DEPTH) {
            return $value;
        }
        if (! array_is_list($value)) {
            if (($value['type'] ?? null) === 'docField' && is_array($value['attrs'] ?? null)) {
                unset($value['attrs']['value']);
            }
            if (is_array($value['attrs'] ?? null)) {
                foreach (self::DEFAULTS[$value['type'] ?? ''] ?? [] as $key => $default) {
                    if (($value['attrs'][$key] ?? null) === $default) {
                        unset($value['attrs'][$key]);
                    }
                }
            }
            if (is_array($value['marks'] ?? null) && array_is_list($value['marks'])) {
                usort($value['marks'], fn ($a, $b) => strcmp((string) json_encode($a), (string) json_encode($b)));
            }
            $value = array_filter($value, fn ($v) => $v !== null && $v !== []);
            ksort($value);
        }

        return array_map(fn ($v) => self::canonical($v, $depth + 1), $value);
    }

    /** @param array<string, mixed> $section */
    private static function group(array $section): string
    {
        return $section['attrs']['templateSectionId'] ?? $section['attrs']['sectionId'];
    }

    /** @param array<string, string> $groups */
    private static function lookup(array $groups, mixed $id): ?string
    {
        return is_string($id) ? ($groups[$id] ?? null) : null;
    }
}
