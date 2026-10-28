<?php

namespace Modules\Mail;

use App\Auth\Permissions;
use App\Events\PersonnelDeleted;
use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Models\User;
use App\Modules\ModuleProvider;
use App\Navigation\NavigationCounters;
use App\Notifications\NotificationKinds;
use App\Search\SearchRegistry;
use App\Support\SettingDefinitions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Mail\Console\BackfillMailboxesCommand;
use Modules\Mail\Console\MailCleanupCommand;
use Modules\Mail\Events\MessageSent;
use Modules\Mail\Http\Middleware\MailNoStore;
use Modules\Mail\Listeners\SyncMailbox;
use Modules\Mail\Models\Attachment;
use Modules\Mail\Models\Mailbox;
use Modules\Mail\Models\MailList;
use Modules\Mail\Models\MailMessage;
use Modules\Mail\Notifications\MailType;
use Modules\Mail\Policies\MailboxPolicy;
use Modules\Mail\Policies\MailListPolicy;
use Modules\Mail\Search\MailSource;
use Modules\Mail\Support\AddressGenerator;
use Modules\Mail\Support\AddressRules;
use Modules\Mail\Support\MailboxAccess;
use Modules\Mail\Support\MailboxAccessMemo;
use Modules\Mail\Support\MailboxProvisioner;
use Modules\Mail\Support\MailCounter;

/**
 * Mail: the internal mailbox system (legacy plugin `mail`). Nothing leaves the system: no SMTP, Laravel's Mail facade
 * and config/mail.php are not involved. Content is reached only through MailDelivery::visibleTo (never Gate/permissions).
 */
class MailServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Mail';
    }

    public function label(): string
    {
        return 'mail.label';
    }

    public function description(): string
    {
        return 'mail.description';
    }

    public function register(): void
    {
        $this->app->scoped(MailboxAccessMemo::class);

        // No permission may start with mail.message / mail.delivery: those ability prefixes are kept from Gate::before.
        foreach (['mail.use', 'mail.lists.manage', 'mail.domain.choose', 'mail.admin'] as $name) {
            Permissions::register($name, 'mail', 'mail');
        }

        $defs = $this->app->make(SettingDefinitions::class);
        $defs->register('mail.domain', 'mail', 'string', AddressRules::DEFAULT_DOMAIN, ['max:100'], editable: false, module: 'mail');
        $defs->register('mail.address_pattern', 'mail', 'enum', 'initial_dot_last', options: AddressGenerator::PATTERNS, editable: false, module: 'mail');
        $defs->register('mail.allowed_domains', 'mail', 'list', [], editable: false, module: 'mail');
        $defs->register('mail.send_cooldown', 'mail', 'int', 10, ['min:0', 'max:3600'], editable: false, module: 'mail');

        $this->app->make(NotificationKinds::class)->register(MailType::KEY, MailType::definition());
        $this->app->make(SearchRegistry::class)->register(new MailSource);
        $this->app->make(NavigationCounters::class)->register('mail', MailCounter::resolve(...), module: 'mail');
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('mail:backfill')->dailyAt('03:15');
        $schedule->command('mail:cleanup')->dailyAt('03:30');
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(RouteMatched::class, fn () => MailboxAccess::forget());

        $this->commands([BackfillMailboxesCommand::class, MailCleanupCommand::class]);
        $this->listen(PersonnelSaved::class, SyncMailbox::class);
        $this->listen(PersonnelDeleted::class, SyncMailbox::class);
        $this->listen('eloquent.deleting: '.Personnel::class, function (Personnel $person): void {
            app(MailboxProvisioner::class)->deactivateFor($person->id);
        });
        $this->listen(MessageSent::class, [MailType::class, 'notify']);

        // Administration pages only (metadata); content never goes through a Gate.
        Gate::policy(Mailbox::class, MailboxPolicy::class);
        Gate::policy(MailList::class, MailListPolicy::class);

        RateLimiter::for('mail-drafts', fn (Request $r) => Limit::perMinute(30)->by('mail-drafts|'.($r->user()?->getAuthIdentifier() ?? $r->ip())));

        // {message} only through the participant scope: a mail without a visible copy is a 404 for
        // everybody, admin and super admin included; no Gate, no policy, so Gate::before never opens it.
        Route::bind('message', function (string $value) {
            $user = auth()->user();
            // The binding runs before the route's NoStore middleware, so its 404 names the header itself.
            abort_unless($user instanceof User && ctype_digit($value), 404, '', MailNoStore::HEADERS);

            return MailMessage::query()->whereKey((int) $value)->whereHas('deliveries', fn ($q) => $q->visibleTo($user))->first()
                ?? abort(404, '', MailNoStore::HEADERS);
        });
        // The same rule for attachments: a copy of their mail, not deleted, in any mailbox the account may open.
        Route::bind('mailAttachment', function (string $value) {
            $user = auth()->user();
            abort_unless($user instanceof User && ctype_digit($value), 404, '', MailNoStore::HEADERS);

            return Attachment::query()->whereKey((int) $value)->whereHas('message.deliveries', fn ($q) => $q->visibleTo($user))->first()
                ?? abort(404, '', MailNoStore::HEADERS);
        });
    }
}
