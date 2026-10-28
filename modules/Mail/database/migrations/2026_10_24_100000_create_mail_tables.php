<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The internal mail system: nothing here ever leaves the system, there is no SMTP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['personal', 'group'])->default('personal');
            $table->foreignId('personnel_id')->nullable()->unique()->constrained('personnel')->nullOnDelete();
            // Ownership is this column alone (never the record link), see MailboxAccess::autoBind.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('address', 190)->unique();
            $table->string('display_name', 150);
            $table->string('domain', 100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::create('mail_mailbox_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mailbox_id', 'user_id']);
        });

        Schema::create('mail_address_history', function (Blueprint $table) {
            $table->id();
            $table->string('address', 190)->unique();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->timestamp('released_at')->nullable();
        });

        Schema::create('mail_lists', function (Blueprint $table) {
            $table->id();
            $table->string('address', 190)->unique();
            $table->string('name', 150);
            $table->enum('kind', ['static', 'dynamic'])->default('static');
            $table->json('rule')->nullable();
            $table->enum('senders', ['all', 'managers'])->default('all');
            $table->timestamps();
        });

        Schema::create('mail_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_list_id')->constrained('mail_lists')->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();

            $table->unique(['mail_list_id', 'mailbox_id']);
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('mail_mailboxes')->restrictOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 255)->default('');
            $table->json('body')->nullable();
            $table->text('search_text')->nullable();
            $table->json('header')->nullable();
            $table->char('thread_id', 32)->index();
            $table->foreignId('in_reply_to')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->enum('status', ['draft', 'sent'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sender_mailbox_id', 'status', 'sent_at'], 'mail_messages_cooldown_index');
        });

        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->enum('role', ['sender', 'to', 'cc', 'bcc']);
            $table->enum('folder', ['inbox', 'sent', 'drafts', 'archive', 'trash']);
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('sorted_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'mailbox_id', 'role']);
            $table->index(['mailbox_id', 'folder', 'deleted_at', 'sorted_at'], 'mail_deliveries_folder_index');
            $table->index(['mailbox_id', 'folder', 'read_at', 'deleted_at', 'role'], 'mail_deliveries_unread_index');
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->boolean('missing')->default(false);
            $table->timestamps();
        });

        Schema::create('mail_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->nullable()->unique()->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->json('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mail_signatures', 'mail_attachments', 'mail_deliveries', 'mail_messages', 'mail_list_members', 'mail_lists',
            'mail_address_history', 'mail_mailbox_members', 'mail_mailboxes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
