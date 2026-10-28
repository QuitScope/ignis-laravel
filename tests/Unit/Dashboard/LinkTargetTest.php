<?php

use App\Dashboard\LinkTarget;

it('accepts relative paths, http(s) with a host and plain mailto', function () {
    foreach ([
        '/', '/vehicles', '/settings/personnel/ranks?x=1#top', '/a:b', '/%2F/x',
        'http://example.org', 'https://example.org/path?q=1#h', 'https://sub.example.org:8443/x', 'https://127.0.0.1/', 'https://[::1]/x',
        'mailto:info@example.org', 'mailto:a.b+c@mail.example.org?subject=Hallo%20Welt', 'mailto:a@b.org?subject=',
    ] as $url) {
        expect(LinkTarget::validate($url))->toBeTrue()->and(LinkTarget::normalise($url))->toBe($url);
    }
});

it('refuses hostile and malformed targets', function () {
    foreach ([
        'javascript:alert(1)', 'JavaScript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", "java\nscript:alert(1)", "java\0script:alert(1)",
        " \x01javascript:alert(1)", 'jav&#x09;ascript:alert(1)', '&#106;avascript:alert(1)', '%6Aavascript:alert(1)',
        'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'ftp://example.org/x', 'blob:https://example.org/x',
        '//evil.example', '///evil.example', '/\\evil.example', '\\\\evil.example', '/x\\y',
        'HTTP://example.org', 'Https://example.org', 'mailto:', 'MAILTO:a@b.org', 'mailto:a@b', 'mailto:a@b.org,c@d.org', 'mailto:a b@c.org',
        'mailto:a@b.org?bcc=x@y.org', 'mailto:a@b.org?cc=x@y.org', 'mailto:a@b.org?body=hi', 'mailto:a@b.org?subject=x&bcc=y@z.org', 'mailto:a@b.org?x=1', 'mailto:a@b.org?',
        'http://', 'https:///x', 'https://user@example.org', 'https://user:pw@example.org', 'https://@example.org', 'https://exa mple.org',
        'https://example.org\\@evil.org', 'https://-bad.example', 'https://exa_mple.org', 'https://example.org:99999', 'https://[::1', 'http:example.org',
        'example.org', 'vehicles', '#anchor', '?x=1', '', ' ', "/x\ty", "/x\ny", "/x\x7fy", "/x\u{200B}y", "/x\u{00A0}y", "\xC3\x28", '/'.str_repeat('a', 255),
    ] as $url) {
        expect(LinkTarget::validate($url))->toBeFalse()->and(LinkTarget::normalise($url))->toBeNull();
    }
});

it('trims blanks around a target only on normalise', function () {
    expect(LinkTarget::normalise('  /vehicles '))->toBe('/vehicles')->and(LinkTarget::validate(' /vehicles'))->toBeFalse();
});
