<?php

namespace Modules\Mail\Support;

use InvalidArgumentException;
use Modules\Mail\Content\MailContentValidator;
use Modules\Mail\Content\PlainText;

/**
 * The posted body (`body_json`, a string) as a `mail` document: size before decoding, nesting depth, the content
 * allowlist (MailContentValidator, strict), and the text the search reads (subject + plain text of the body).
 */
class MailBodyGuard
{
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException with a translated message
     */
    public function decode(string $raw): array
    {
        return MailContentValidator::validate($raw);
    }

    /** @param array<string, mixed>|null $body */
    public function searchText(string $subject, ?array $body): string
    {
        return PlainText::search($subject, $body);
    }
}
