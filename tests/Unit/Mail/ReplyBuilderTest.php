<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Support\ReplyBuilder;
use Tests\TestCase;

require_once __DIR__.'/../../Feature/Mail/mailHelpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->anna = ml_group('Anna Absender', 'anna');
    $this->me = ml_group('Ich', 'ich');
});

function rb_message(array $over = []): MailMessage
{
    $m = ml_message(test()->anna, [], 'Dienstplan');
    $m->forceFill($over + [
        'header' => ['to' => ['ich@ignis.ef', 'bob@ignis.ef'], 'cc' => ['carla@ignis.ef', 'ICH@ignis.ef', 'Bob@ignis.ef'], 'bcc' => ['geheim@ignis.ef']],
        'body' => json_decode(ml_doc('Original'), true),
        'sent_at' => '2026-10-20 08:30:00',
    ])->save();

    return $m->refresh();
}

it('prefixes the subject once', function () {
    expect(ReplyBuilder::prefix('Re:', 'Dienstplan'))->toBe('Re: Dienstplan')
        ->and(ReplyBuilder::prefix('Re:', 'Re: Dienstplan'))->toBe('Re: Dienstplan')
        ->and(ReplyBuilder::prefix('Re:', 'RE: Dienstplan'))->toBe('RE: Dienstplan')
        ->and(ReplyBuilder::prefix('Fwd:', 'Re: Dienstplan'))->toBe('Fwd: Re: Dienstplan')
        ->and(ReplyBuilder::prefix('Fwd:', 'fwd: x'))->toBe('fwd: x')
        ->and(mb_strlen(ReplyBuilder::prefix('Re:', str_repeat('ä', 255))))->toBe(255);
});

it('replies to the sender with the original quoted under a header line', function () {
    $draft = ReplyBuilder::reply(rb_message(), $this->me);

    expect($draft['subject'])->toBe('Re: Dienstplan')->and($draft['to'])->toBe(['anna@ignis.ef'])->and($draft['cc'])->toBe([])
        ->and($draft['body']['content'][0])->toBe(['type' => 'paragraph'])
        ->and($draft['body']['content'][1]['type'])->toBe('blockquote')
        ->and($draft['body']['content'][1]['content'][0]['content'][0]['text'])->toContain('Anna Absender')->toContain('anna@ignis.ef')
        ->and($draft['body']['content'][1]['content'][1])->toBe(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original']]]);
});

it('replies to all without me and without bcc, case-insensitive and without duplicates', function () {
    $draft = ReplyBuilder::replyAll(rb_message(), $this->me);

    expect($draft['to'])->toBe(['anna@ignis.ef', 'bob@ignis.ef'])
        ->and($draft['cc'])->toBe(['carla@ignis.ef'])
        ->and(json_encode($draft))->not->toContain('geheim');
});

it('replies to own sent mail to its recipients', function () {
    $draft = ReplyBuilder::reply(rb_message(), $this->anna);

    expect($draft['to'])->toBe(['ich@ignis.ef', 'bob@ignis.ef']);
});

it('forwards without recipients and with the quote', function () {
    $draft = ReplyBuilder::forward(rb_message(['subject' => 'Fwd: Plan']));

    expect($draft['subject'])->toBe('Fwd: Plan')->and($draft['to'])->toBe([])->and($draft['cc'])->toBe([])
        ->and(json_encode($draft['body']))->toContain('Original');
});

it('quotes a stored body only as far as the preset allows', function () {
    $draft = ReplyBuilder::reply(rb_message(['body' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]],
        ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
    ]]]), $this->me);

    expect(json_encode($draft['body']))->not->toContain('javascript')->not->toContain('script');
});

it('quotes a stored body the validator refuses as an empty quote instead of failing', function (array $body) {
    foreach ([ReplyBuilder::reply(rb_message(['body' => $body]), $this->me), ReplyBuilder::forward(rb_message(['body' => $body]))] as $draft) {
        expect($draft['body']['content'][1]['type'])->toBe('blockquote')
            ->and($draft['body']['content'][1]['content'])->toHaveCount(1)
            ->and($draft['body']['content'][1]['content'][0]['content'][0]['text'])->toContain('anna@ignis.ef');
    }
})->with([
    'no document' => [['nope']],
    'wrong root' => [['type' => 'paragraph', 'content' => []]],
    'too deep' => [['type' => 'doc', 'content' => [array_reduce(range(1, 40), fn ($inner) => ['type' => 'blockquote', 'content' => [$inner]], ['type' => 'paragraph'])]]],
]);
