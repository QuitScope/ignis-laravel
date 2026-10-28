<?php

it('has no password reset leftovers in config/auth.php', function () {
    expect(file_get_contents(config_path('auth.php')))->not->toContain('passwords')
        ->not->toContain('password_reset_tokens')
        ->not->toContain('throttle')
        ->and(config('auth.defaults'))->toBe(['guard' => 'web']);
});
