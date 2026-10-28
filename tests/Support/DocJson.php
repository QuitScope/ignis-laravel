<?php

namespace Tests\Support;

/** Builders for ProseMirror document JSON in the document tests. */
final class DocJson
{
    public static function legacyTemplates(): array
    {
        $all = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/documents/legacy-system-templates.json'), true);

        return array_map(fn (array $t) => self::doc(...$t['sections']), $all);
    }

    public static function doc(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    public static function section(array $content, string $mode = 'free', string $id = 's1', array $attrs = []): array
    {
        return ['type' => 'docSection', 'attrs' => ['mode' => $mode, 'sectionId' => $id] + $attrs, 'content' => $content];
    }

    public static function para(array $content = [], array $attrs = []): array
    {
        return array_filter(['type' => 'paragraph', 'attrs' => $attrs, 'content' => $content]);
    }

    public static function text(string $text, string ...$marks): array
    {
        return array_filter(['type' => 'text', 'text' => $text, 'marks' => array_map(fn ($m) => ['type' => $m], $marks)]);
    }

    public static function variable(string $name): array
    {
        return ['type' => 'docVariable', 'attrs' => ['name' => $name]];
    }

    public static function field(string $id, array $attrs = []): array
    {
        return ['type' => 'docField', 'attrs' => ['fieldId' => $id] + $attrs];
    }
}
