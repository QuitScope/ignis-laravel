<?php

use App\Update\SignatureVerifier;
use Tests\Support\UpdateFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => config(['ignis.update.public_key' => UpdateFixtures::publicKey()]));

it('accepts a valid signature', function () {
    expect(SignatureVerifier::isConfigured())->toBeTrue()
        ->and(SignatureVerifier::verify('payload', UpdateFixtures::sign('payload')))->toBeTrue();
});

it('rejects an invalid signature', function () {
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    expect(SignatureVerifier::verify('payload!', UpdateFixtures::sign('payload')))->toBeFalse()
        ->and(SignatureVerifier::verify('payload', base64_encode(sodium_crypto_sign_detached('payload', $other))))->toBeFalse()
        ->and(SignatureVerifier::verify('payload', 'not base64 %%%'))->toBeFalse()
        ->and(SignatureVerifier::verify('payload', base64_encode('short')))->toBeFalse();
});

it('rejects a missing signature', function () {
    expect(SignatureVerifier::verify('payload', ''))->toBeFalse();
});

it('rejects everything with the placeholder key or a broken key', function (mixed $key) {
    config(['ignis.update.public_key' => $key]);

    expect(SignatureVerifier::isConfigured())->toBeFalse()
        ->and(SignatureVerifier::verify('payload', UpdateFixtures::sign('payload')))->toBeFalse();
})->with(['PLACEHOLDER', '', null, 'c2hvcnQ=']);

it('ships the placeholder key', function () {
    expect(trim((string) file_get_contents(resource_path('update/public.key'))))->toBe('PLACEHOLDER');
});
