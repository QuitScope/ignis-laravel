<?php

use App\Discord\WebhookUrl;

$token = 'abcDEF_123-xyz.456';
$id = '123456789012345678';

it('accepts the four Discord hosts', function (string $host) use ($id, $token) {
    expect(WebhookUrl::parse("https://{$host}/api/webhooks/{$id}/{$token}"))
        ->toBe(['host' => $host, 'id' => $id, 'token' => $token]);
})->with(['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com']);

it('refuses everything else', function () {
    foreach ([
        'other host' => 'https://evil.com/api/webhooks/123456789012345678/tok',
        'suffix host' => 'https://discord.com.evil.com/api/webhooks/123456789012345678/tok',
        'userinfo' => 'https://discord.com@evil.com/api/webhooks/123456789012345678/tok',
        'userinfo to discord' => 'https://user:pw@discord.com/api/webhooks/123456789012345678/tok',
        'http' => 'http://discord.com/api/webhooks/123456789012345678/tok',
        'port' => 'https://discord.com:8443/api/webhooks/123456789012345678/tok',
        'default port' => 'https://discord.com:443/api/webhooks/123456789012345678/tok',
        'fragment' => 'https://discord.com/api/webhooks/123456789012345678/tok#x',
        'query' => 'https://discord.com/api/webhooks/123456789012345678/tok?wait=true',
        'cyrillic lookalike' => "https://d\u{0456}scord.com/api/webhooks/123456789012345678/tok",
        'punycode' => 'https://xn--dscord-n1d.com/api/webhooks/123456789012345678/tok',
        'fullwidth dot' => "https://discord\u{FF0E}com/api/webhooks/123456789012345678/tok",
        'wrong path' => 'https://discord.com/api/v10/webhooks/123456789012345678/tok',
        'path prefix' => 'https://discord.com/x/api/webhooks/123456789012345678/tok',
        'id not digits' => 'https://discord.com/api/webhooks/12345abc/tok',
        'no token' => 'https://discord.com/api/webhooks/123456789012345678/',
        'extra segment' => 'https://discord.com/api/webhooks/123456789012345678/tok/github',
        'dot segments' => 'https://discord.com/api/webhooks/123456789012345678/..',
        'long token' => 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('a', 101),
        'uppercase host' => 'https://DISCORD.COM/api/webhooks/123456789012345678/tok',
        'leading space' => ' https://discord.com/api/webhooks/123456789012345678/tok',
        'trailing newline' => "https://discord.com/api/webhooks/123456789012345678/tok\n",
        'backslash' => 'https://discord.com\@evil.com/api/webhooks/123456789012345678/tok',
        'empty' => '',
    ] as $url) {
        expect(WebhookUrl::parse($url))->toBeNull();
    }
});

it('takes a token up to 100 characters', function () {
    expect(WebhookUrl::parse('https://discord.com/api/webhooks/123456789012345678/'.str_repeat('a', 100)))->not->toBeNull();
});
