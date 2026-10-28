<?php

namespace Modules\KnowledgeBase;

use App\Auth\Permissions;
use App\Modules\ModuleProvider;
use App\Search\SearchRegistry;
use App\Support\SettingDefinitions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\KnowledgeBase\Console\KbImagesCleanupCommand;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Policies\KbEntryPolicy;
use Modules\KnowledgeBase\Search\KbSource;

/** Wissensdatenbank (Lexikon): articles with categories and tags; optionally readable without login. */
class KnowledgeBaseServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'KnowledgeBase';
    }

    public function label(): string
    {
        return 'knowledgebase.label';
    }

    public function description(): string
    {
        return 'knowledgebase.description';
    }

    public function register(): void
    {
        // No kb.view: every account reads.
        Permissions::register('kb.edit', 'knowledgebase', 'knowledgebase');
        Permissions::register('kb.archive', 'knowledgebase', 'knowledgebase');

        $this->app->make(SettingDefinitions::class)
            ->register('knowledgebase.public_access', group: 'features', type: 'bool', default: false, module: 'knowledgebase');
        $this->app->make(SearchRegistry::class)->register(new KbSource);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('kb:images:cleanup')->dailyAt('03:45');
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([KbImagesCleanupCommand::class]);

        Gate::policy(KbEntry::class, KbEntryPolicy::class);
        // Public pages: 60 per minute and address for guests; signed-in accounts are not limited here.
        RateLimiter::for('kb-read', fn (Request $r) => $r->user() ? Limit::none() : Limit::perMinute(60)->by('kb-read|'.$r->ip()));
    }
}
