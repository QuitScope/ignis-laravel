<?php

namespace App\Documents;

/**
 * Strict allowlist for editor JSON (ProseMirror), run before anything is stored or rendered. Only the
 * nodes, marks and attributes of the `document` preset pass; every attribute value is
 * checked against its type or enum. Unknown things are rejected, not dropped, so nothing is lost
 * silently; only empty values (null, [], the editor's defaults) are dropped from the result.
 */
final class DocumentValidator
{
    public const MAX_BYTES = 1024 * 1024;

    public const MAX_DEPTH = 64;

    private const MAX_TABLE_ROWS = 200;

    private const MAX_TABLE_CELLS_PER_ROW = 30;

    private const MAX_TABLE_GRID = 2000;

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const VARIABLE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/D';

    private const MARKS = ['bold', 'italic', 'underline', 'strike'];

    private const INLINE = ['text', 'hardBreak', 'docVariable', 'docField'];

    private const BLOCK = ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'table', 'horizontalRule', 'pageBreak'];

    private const CHILDREN = [
        'doc' => [...self::BLOCK, 'docSection'],
        'docSection' => self::BLOCK,
        'paragraph' => self::INLINE,
        'heading' => self::INLINE,
        'blockquote' => self::BLOCK,
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCK,
        'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'],
        'tableCell' => self::BLOCK,
        'tableHeader' => self::BLOCK,
        'horizontalRule' => null,
        'pageBreak' => null,
        'text' => null,
        'hardBreak' => null,
        'docVariable' => null,
        'docField' => null,
    ];

    private const CELL = ['colspan' => 'colspan', 'rowspan' => 'rowspan', 'colwidth' => 'widths'];

    private const ATTRS = [
        'paragraph' => ['textAlign' => 'align'],
        'heading' => ['level' => 'level', 'textAlign' => 'align'],
        'orderedList' => ['start' => 'start', 'type' => 'listType'],
        'tableCell' => self::CELL,
        'tableHeader' => self::CELL,
        'docSection' => ['mode' => 'mode', 'sectionId' => 'id', 'templateSectionId' => 'id', 'repeatable' => 'bool', 'title' => 'label', 'hint' => 'hint'],
        'docVariable' => ['name' => 'variable'],
        'docField' => ['fieldId' => 'id', 'label' => 'label', 'required' => 'bool', 'maxLength' => 'maxLength', 'options' => 'options', 'value' => 'value'],
    ];

    private const REQUIRED = ['heading' => ['level'], 'docSection' => ['mode', 'sectionId'], 'docVariable' => ['name'], 'docField' => ['fieldId']];

    /** @var array<string, true> */
    private array $reasons = [];

    private function __construct(private readonly bool $template) {}

    /**
     * @param  array<mixed>  $doc
     * @param  bool  $template  templates: only sections on top, unique groups and field ids
     * @return array<string, mixed> the cleaned document
     *
     * @throws InvalidDocument
     */
    public static function validate(array $doc, bool $template): array
    {
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidDocument([trans('documents.invalid.json')]);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidDocument([trans('documents.invalid.size')]);
        }

        $self = new self($template);
        $clean = $self->root($doc);
        if ($self->reasons !== []) {
            throw new InvalidDocument(array_slice(array_keys($self->reasons), 0, 20));
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $doc
     * @return array<string, mixed>
     */
    private function root(array $doc): array
    {
        if (($doc['type'] ?? null) !== 'doc' || ! is_array($doc['content'] ?? null) || ! array_is_list($doc['content']) || array_diff(array_keys($doc), ['type', 'content']) !== []) {
            $this->fail(trans('documents.invalid.structure'));

            return [];
        }

        $sections = array_filter($doc['content'], fn ($n) => is_array($n) && ($n['type'] ?? null) === 'docSection');
        if ($this->template && ($doc['content'] === [] || count($sections) !== count($doc['content']))) {
            $this->fail(trans('documents.invalid.template_top'));
        }

        $content = [];
        foreach ($doc['content'] as $node) {
            $content[] = $this->node($node, 'doc', 2);
        }
        if ($this->reasons === []) {
            $this->checkSectionIds($content);
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function node(mixed $node, string $parent, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail(trans('documents.invalid.depth', ['max' => self::MAX_DEPTH]));

            return [];
        }
        $type = is_array($node) ? ($node['type'] ?? null) : null;
        if (! is_string($type) || ! array_key_exists($type, self::CHILDREN)) {
            $this->fail(trans('documents.invalid.unknown_node', ['type' => is_string($type) ? self::short($type) : '?']));

            return [];
        }
        /** @var array<string, mixed> $node */
        if (! in_array($type, self::CHILDREN[$parent] ?? [], true)) {
            $this->fail(trans('documents.invalid.not_allowed', ['type' => $type, 'parent' => $parent]));
        }

        $children = self::CHILDREN[$type];
        $allowedKeys = ['type', 'attrs', ...($children === null ? [] : ['content']), ...($type === 'text' ? ['text'] : []), ...(in_array($type, self::INLINE, true) ? ['marks'] : [])];
        foreach (array_diff(array_keys($node), $allowedKeys) as $key) {
            $this->fail(trans('documents.invalid.bad_key', ['type' => $type, 'key' => self::short($key)]));
        }

        $clean = ['type' => $type];
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || $node['text'] === '') {
                $this->fail(trans('documents.invalid.empty_text'));
            } else {
                $clean['text'] = $node['text'];
            }
        }

        $attrs = $this->attrs($type, $node['attrs'] ?? []);
        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        if ($children !== null && ($node['content'] ?? []) !== []) {
            if (! is_array($node['content']) || ! array_is_list($node['content'])) {
                $this->fail(trans('documents.invalid.content_list', ['type' => $type]));
            } else {
                $clean['content'] = array_map(fn ($child) => $this->node($child, $type, $depth + 1), $node['content']);
            }
        }

        if ($type === 'table') {
            $this->checkTable($node);
        }

        $marks = $node['marks'] ?? [];
        if ($marks !== []) {
            $clean['marks'] = $this->marks($marks);
        }

        return $clean;
    }

    /**
     * Dompdf's cell map allocates the whole span grid, so a small JSON with huge spans can exhaust memory:
     * bound rows, cells per row and the summed colspan x rowspan area of one table.
     *
     * @param  array<string, mixed>  $table
     */
    private function checkTable(array $table): void
    {
        $rows = is_array($table['content'] ?? null) ? $table['content'] : [];
        if (count($rows) > self::MAX_TABLE_ROWS) {
            $this->fail(trans('documents.invalid.table_rows', ['max' => self::MAX_TABLE_ROWS]));
        }
        $area = 0;
        foreach ($rows as $row) {
            $cells = is_array($row) && is_array($row['content'] ?? null) ? $row['content'] : [];
            if (count($cells) > self::MAX_TABLE_CELLS_PER_ROW) {
                $this->fail(trans('documents.invalid.table_cells', ['max' => self::MAX_TABLE_CELLS_PER_ROW]));
            }
            foreach ($cells as $cell) {
                $a = is_array($cell) && is_array($cell['attrs'] ?? null) ? $cell['attrs'] : [];
                $col = is_int($a['colspan'] ?? null) ? $a['colspan'] : 1;
                $rowspan = is_int($a['rowspan'] ?? null) ? $a['rowspan'] : 1;
                $area += max(1, min($col, 100)) * max(1, min($rowspan, 100));
            }
        }
        if ($area > self::MAX_TABLE_GRID) {
            $this->fail(trans('documents.invalid.table_grid', ['max' => self::MAX_TABLE_GRID]));
        }
    }

    /** @return array<string, mixed> */
    private function attrs(string $type, mixed $attrs): array
    {
        if (! is_array($attrs)) {
            $this->fail(trans('documents.invalid.attrs', ['type' => $type]));

            return [];
        }

        $rules = self::ATTRS[$type] ?? [];
        $clean = [];
        foreach ($attrs as $name => $value) {
            $rule = $rules[$name] ?? null;
            if ($rule === null) {
                $this->fail(trans('documents.invalid.bad_attr', ['type' => $type, 'name' => self::short($name)]));
            } elseif ($value !== null && $value !== []) {
                if ($this->valid($rule, $value)) {
                    $clean[$name] = $value;
                } else {
                    $this->fail(trans('documents.invalid.bad_value', ['type' => $type, 'name' => self::short($name)]));
                }
            }
        }

        foreach (self::REQUIRED[$type] ?? [] as $name) {
            if (! isset($clean[$name])) {
                $this->fail(trans('documents.invalid.missing_attr', ['type' => $type, 'name' => $name]));
            }
        }

        if ($type === 'docField' && isset($clean['value'], $clean['maxLength']) && mb_strlen($clean['value']) > $clean['maxLength']) {
            $this->fail(trans('documents.invalid.value_length'));
        }

        return $clean;
    }

    private function valid(string $rule, mixed $v): bool
    {
        return match ($rule) {
            'align' => in_array($v, ['left', 'center', 'right', 'justify'], true),
            'level' => in_array($v, [1, 2, 3], true),
            'start' => is_int($v) && $v >= 0 && $v <= 99999,
            'listType' => in_array($v, ['1', 'a', 'A', 'i', 'I'], true),
            'colspan' => is_int($v) && $v >= 1 && $v <= 20,
            'rowspan' => is_int($v) && $v >= 1 && $v <= 50,
            'widths' => is_array($v) && array_is_list($v) && count($v) <= 100
                && array_filter($v, fn ($w) => ! is_int($w) || $w < 0 || $w > 5000) === [],
            'mode' => in_array($v, ['free', 'locked'], true),
            'id' => is_string($v) && preg_match(self::ID_PATTERN, $v) === 1,
            'bool' => is_bool($v),
            'label' => $this->text($v, 200),
            'hint' => $this->text($v, 1000),
            'value' => $this->text($v, 10000),
            'variable' => is_string($v) && strlen($v) <= 64 && preg_match(self::VARIABLE_PATTERN, $v) === 1,
            'maxLength' => is_int($v) && $v >= 1 && $v <= 10000,
            'options' => is_array($v) && array_is_list($v) && count($v) <= 200
                && array_filter($v, fn ($o) => ! $this->option($o)) === [],
            default => false,
        };
    }

    private function option(mixed $o): bool
    {
        return is_array($o) && array_diff(array_keys($o), ['label', 'male', 'female']) === []
            && $this->text($o['label'] ?? null, 200) && ($o['label'] !== '')
            && (! isset($o['male']) || $this->text($o['male'], 200))
            && (! isset($o['female']) || $this->text($o['female'], 200));
    }

    private function text(mixed $v, int $max): bool
    {
        return is_string($v) && mb_strlen($v) <= $max;
    }

    /** @return list<array{type: string}> */
    private function marks(mixed $marks): array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->fail(trans('documents.invalid.marks'));

            return [];
        }

        $clean = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! in_array($type, self::MARKS, true) || array_diff(array_keys($mark), ['type', 'attrs']) !== [] || ($mark['attrs'] ?? []) !== []) {
                $this->fail(trans('documents.invalid.unknown_mark', ['type' => is_string($type) ? self::short($type) : '?']));

                continue;
            }
            $clean[] = ['type' => $type];
        }

        return $clean;
    }

    /**
     * sectionIds are unique in every document; in a template each group (templateSectionId, else the
     * sectionId) appears once, is no other section's sectionId, and each section's fieldIds are unique.
     *
     * @param  list<array<string, mixed>>  $content
     */
    private function checkSectionIds(array $content): void
    {
        $ids = [];
        $groups = [];
        foreach ($content as $node) {
            if ($node['type'] !== 'docSection' || ! isset($node['attrs']['sectionId'])) {
                continue;
            }
            $id = $node['attrs']['sectionId'];
            if (isset($ids[$id])) {
                $this->fail(trans('documents.invalid.dup_section', ['id' => $id]));
            }
            $ids[$id] = true;

            if ($this->template) {
                $group = $node['attrs']['templateSectionId'] ?? $id;
                if (isset($groups[$group])) {
                    $this->fail(trans('documents.invalid.dup_group', ['id' => $group]));
                }
                $groups[$group] = $id;

                $fieldIds = self::fieldIds($node);
                foreach (array_unique(array_diff_assoc($fieldIds, array_unique($fieldIds))) as $dup) {
                    $this->fail(trans('documents.invalid.dup_field', ['id' => $dup]));
                }
            }
        }

        // A group id naming another section would route that section's posted content (SectionGuard).
        foreach ($groups as $group => $sectionId) {
            if ((string) $group !== $sectionId && isset($ids[$group])) {
                $this->fail(trans('documents.invalid.group_is_section', ['id' => $group]));
            }
        }
    }

    public static function short(int|string $name): string
    {
        $name = (string) $name;

        return mb_strlen($name) > 40 ? mb_substr($name, 0, 40).'…' : $name;
    }

    /**
     * fieldIds below a (validated) node, in document order.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public static function fieldIds(array $node): array
    {
        if ($node['type'] === 'docField') {
            return [$node['attrs']['fieldId']];
        }

        return array_merge([], ...array_map(self::fieldIds(...), $node['content'] ?? []));
    }

    private function fail(string $reason): void
    {
        $this->reasons[$reason] = true;
    }
}
