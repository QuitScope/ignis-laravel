<?php

use Illuminate\Support\Facades\Route;
use Modules\Mail\Http\Controllers\AttachmentController;
use Modules\Mail\Http\Controllers\ComposeController;
use Modules\Mail\Http\Controllers\DraftController;
use Modules\Mail\Http\Controllers\InboxController;
use Modules\Mail\Http\Controllers\MailAdminController;
use Modules\Mail\Http\Controllers\MailListController;
use Modules\Mail\Http\Controllers\MailSettingsController;
use Modules\Mail\Http\Controllers\SignatureController;
use Modules\Mail\Http\Middleware\MailNoStore;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;

// ModuleProvider wraps this file in ['module:mail', 'web']. {message} is bound through MailDelivery::visibleTo (provider).
Route::middleware([MailNoStore::class, 'auth'])->group(function () {
    Route::controller(InboxController::class)->group(function () {
        $folders = InboxController::FOLDERS;
        Route::get('/mail', 'index');
        Route::get('/mail/addressbook', 'addressbook');
        Route::get('/mail/{folder}', 'index')->whereIn('folder', $folders);
        Route::get('/mail/{folder}/{message}', 'show')->whereIn('folder', $folders)->whereNumber('message');
        Route::patch('/mail/messages/{message}/folder', 'move')->whereNumber('message');
        Route::patch('/mail/messages/{message}/read', 'read')->whereNumber('message');
        Route::patch('/mail/messages/{message}/flag', 'flag')->whereNumber('message');
        Route::delete('/mail/messages/{message}', 'destroy')->whereNumber('message');
    });

    Route::controller(ComposeController::class)->group(function () {
        Route::get('/mail/compose', 'create');
        Route::get('/mail/compose/draft/{message}', 'draft')->whereNumber('message');
        Route::get('/mail/compose/{mode}/{message}', 'original')->whereIn('mode', ['reply', 'reply-all', 'forward'])->whereNumber('message');
    });
    Route::get('/mail/signature', [SignatureController::class, 'edit']);
    // {mailAttachment} is bound through MailDelivery::visibleTo as well (provider).
    Route::get('/mail/attachments/{mailAttachment}', [AttachmentController::class, 'show'])->whereNumber('mailAttachment');

    // Lists (mail.lists.manage) and the administration (mail.admin): metadata only, never a message.
    Route::middleware('can:manage,'.MailList::class)->controller(MailListController::class)->whereNumber('list')->group(function () {
        Route::get('/mail/lists', 'index');
        Route::get('/mail/lists/create', 'create');
        Route::post('/mail/lists', 'store');
        Route::get('/mail/lists/{list}/edit', 'edit');
        Route::put('/mail/lists/{list}', 'update');
        Route::delete('/mail/lists/{list}', 'destroy');
    });
    Route::middleware('can:manage,'.Mailbox::class)->prefix('settings/mail')->whereNumber(['mailbox', 'user'])->group(function () {
        Route::get('/', [MailSettingsController::class, 'edit']);
        Route::put('/', [MailSettingsController::class, 'update']);
        Route::controller(MailAdminController::class)->prefix('mailboxes')->group(function () {
            Route::get('/', 'index');
            Route::get('/groups/create', 'createGroup');
            Route::post('/groups', 'storeGroup');
            Route::get('/{mailbox}/edit', 'edit');
            Route::put('/{mailbox}', 'updateAddress');
            Route::patch('/{mailbox}/name', 'rename');
            Route::patch('/{mailbox}/lock', 'lock');
            Route::put('/{mailbox}/account', 'assignAccount');
            Route::delete('/{mailbox}/account', 'releaseAccount');
            Route::post('/{mailbox}/members', 'addMember');
            Route::delete('/{mailbox}/members/{user}', 'removeMember');
        });
    });

    Route::middleware('throttle:mail-drafts')->group(function () {
        Route::controller(DraftController::class)->group(function () {
            Route::post('/mail/drafts', 'store');
            Route::put('/mail/drafts/{message}', 'update')->whereNumber('message');
            Route::post('/mail/drafts/{message}/send', 'send')->whereNumber('message');
        });
        Route::post('/mail/drafts/{message}/attachments', [AttachmentController::class, 'store'])->whereNumber('message');
        Route::delete('/mail/attachments/{mailAttachment}', [AttachmentController::class, 'destroy'])->whereNumber('mailAttachment');
        Route::put('/mail/signature', [SignatureController::class, 'update']);
    });
});
