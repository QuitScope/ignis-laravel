<?php

namespace App\Documents\Nodes;

use Tiptap\Core\Node;

/**
 * A fillable field (inline atom): `<span class="efe-field">value</span>`. Label, options etc. are never
 * rendered. Options: `onRequiredEmpty` callback(label) for the "Pflichtfeld leer" warning.
 */
class DocField extends Node
{
    /** @var string */
    public static $name = 'docField';

    /**
     * The value a field may hold: a string, cut to maxLength, and with options only one of their
     * label/male/female forms (anything else becomes '').
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function clean(array $attrs, mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $options = is_array($attrs['options'] ?? null) ? $attrs['options'] : [];
        if ($options !== []) {
            $allowed = [];
            foreach ($options as $o) {
                foreach (['label', 'male', 'female'] as $form) {
                    if (is_array($o) && is_string($o[$form] ?? null)) {
                        $allowed[] = $o[$form];
                    }
                }
            }
            if (! in_array($value, $allowed, true)) {
                return '';
            }
        }

        $max = $attrs['maxLength'] ?? null;

        return is_int($max) && $max > 0 ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * @param  object  $node
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<int, mixed>
     */
    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['span', ['class' => 'efe-field'], 0];
    }

    /** @param object{attrs?: object} $node */
    public function renderText($node): string
    {
        $attrs = json_decode((string) json_encode($node->attrs ?? []), true);
        $value = self::clean($attrs, $attrs['value'] ?? '');

        if (trim($value) === '' && ($attrs['required'] ?? false) === true) {
            ($this->options['onRequiredEmpty'])(($attrs['label'] ?? '') ?: $attrs['fieldId']);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array<string, mixed> */
    public function addOptions()
    {
        return ['onRequiredEmpty' => fn (string $label) => null];
    }
}
