<?php

use App\Discord\Embeds;

it('survives an embed without fields that is too long', function () {
    $embed = ['title' => str_repeat('t', 3500), 'description' => str_repeat('d', 3500)];
    expect(Embeds::fit($embed))->toBe($embed);
});

it('keeps field values at one character when title and description alone exceed the limit', function () {
    $embed = ['title' => str_repeat('t', 3500), 'description' => str_repeat('d', 3500), 'fields' => [['name' => 'n', 'value' => 'vvvv']]];
    expect(Embeds::fit($embed)['fields'][0]['value'])->toBe('…');
});
