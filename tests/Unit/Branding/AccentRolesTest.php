<?php

use App\Branding\AccentRoles;

it('computes the legacy accent roles: dark text on the stock orange, a shifted second stop for a light accent', function () {
    $dark = ['canvas' => '#08090b', 'surface' => '#141517', 'overlay' => '#1f2023', 'control' => '#2d2e30', 'rim' => '#2d2e30', 'track' => '#0c0d0f', 'card' => '#1c1d1f', 'well' => '#161719', 'danger' => '#f93f4e'];

    expect(AccentRoles::for('#f0500a', $dark))->toBe([
        'on' => '#22110b', 'press' => '#ffffff', 'fill' => '#f0500a', 'fill2' => '#d96e00',
        'text' => '#f26c31', 'focus' => '#f0500a', 'bar' => '#f0500a', 'dangerFill' => '#db3745',
    ]);
    $yellow = AccentRoles::for('#ffd400', $dark);
    expect($yellow['on'])->toBe('#22110b')->and($yellow['fill2'])->not->toBe($yellow['fill']);
    expect(AccentRoles::for('#2255aa', $dark))->toMatchArray(['on' => '#ffffff', 'press' => '#000000', 'text' => '#7696ca']);
});
