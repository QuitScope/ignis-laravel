<?php

use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Mail\Models\MailMessage;
use Tests\Support\LegacyDatabase;

require_once __DIR__.'/mailHelpers.php';

it('counts a mail that went out while the send waited for the mailbox lock', function () {
    LegacyDatabase::configure();
    $base = array_merge(config('database.connections.mariadb'), array_intersect_key(config('database.connections.legacy'), array_flip(['host', 'port', 'username', 'password'])));
    config(['database.connections.mail_race_admin' => array_merge($base, ['database' => 'legacy_test'])]);
    config(['database.connections.mail_race' => array_merge($base, ['database' => 'legacy_test_mail'])]);
    config(['database.connections.mail_race_other' => array_merge($base, ['database' => 'legacy_test_mail'])]);
    LegacyDatabase::assertTestDatabase(config('database.connections.mail_race'), config('database.connections.sqlite'));

    DB::connection('mail_race_admin')->statement('DROP DATABASE IF EXISTS legacy_test_mail');
    DB::connection('mail_race_admin')->statement('CREATE DATABASE legacy_test_mail');
    try {
        config(['database.default' => 'mail_race']);
        DB::purge('mail_race');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(RoleSeeder::class);
        app(Settings::class)->set('mail.send_cooldown', 10);

        $member = ml_member('Parallel Schreiber');
        $this->actingAs($member['user']);
        $fields = ['subject' => 'Zweiter Tab', 'body_json' => ml_doc('Text'), 'to' => [$member['mailbox']->address]];
        $draft = ml_draft($this, $fields);

        $other = DB::connection('mail_race_other');
        $other->statement('SET SESSION foreign_key_checks = 0');
        $parallelSent = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use ($other, $member, &$parallelSent) {
            if ($parallelSent || $q->connectionName !== 'mail_race' || ! str_contains($q->sql, 'mail_mailboxes') || ! str_contains($q->sql, 'for update')) {
                return;
            }
            $parallelSent = true;
            $other->table('mail_messages')->insert([
                'sender_mailbox_id' => $member['mailbox']->id, 'subject' => 'Erster Tab', 'thread_id' => bin2hex(random_bytes(16)),
                'status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $response = $this->postJson("/mail/drafts/$draft/send", $fields);

        expect($parallelSent)->toBeTrue('the send transaction did not lock the mailbox');
        $response->assertStatus(429);
        expect(MailMessage::query()->findOrFail($draft)->status)->toBe('draft');
    } finally {
        DB::purge('mail_race');
        DB::purge('mail_race_other');
        DB::connection('mail_race_admin')->statement('DROP DATABASE IF EXISTS legacy_test_mail');
    }
})->group('legacy')->skip(fn () => ! LegacyDatabase::available(), 'LEGACY_TEST_DB_HOST not set');
