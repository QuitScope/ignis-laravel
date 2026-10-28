<?php

namespace Modules\Forms;

use App\Auth\Permissions;
use App\Dashboard\DashboardWidgets;
use App\Dashboard\Widgets\HintsWidget;
use App\Models\User;
use App\Modules\ModuleProvider;
use App\Notifications\NotificationKinds;
use Illuminate\Support\Facades\Gate;
use Modules\Forms\Models\FormSubmission;
use Modules\Forms\Policies\FormSubmissionPolicy;
use Modules\Forms\Widgets\OwnApplicationsWidget;

/** Anträge: configurable application types with fields, submissions, decisions and the applicant's own list. */
class FormsServiceProvider extends ModuleProvider
{
    public function moduleName(): string
    {
        return 'Forms';
    }

    public function label(): string
    {
        return 'forms.label';
    }

    public function description(): string
    {
        return 'forms.description';
    }

    public function register(): void
    {
        Permissions::register('application.view', 'application', 'forms');
        Permissions::register('application.edit', 'application', 'forms');

        $this->app->make(NotificationKinds::class)->register('antrag', ['label' => 'forms.notification.kind', 'icon' => 'file-text', 'module' => 'forms']);
        $this->app->make(DashboardWidgets::class)->register(new OwnApplicationsWidget);
        $this->app->make(HintsWidget::class)->addSource('forms', fn (User $u) => $u->hasPermission(['admin', 'application.edit']), fn () => self::openHints());
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(FormSubmission::class, FormSubmissionPolicy::class);
    }

    /** @return list<array{tone: string, icon: string, title: string, href: string}> one hint for all open submissions, oldest first */
    public static function openHints(): array
    {
        $open = FormSubmission::query()->whereIn('status', FormSubmission::OPEN);
        $count = (clone $open)->count();
        if ($count === 0) {
            return [];
        }
        $first = $open->with('type:id,name')->orderBy('submitted_at')->orderBy('id')->limit(3)->get();
        $names = $first->map(fn (FormSubmission $s) => $s->type->name.' #'.$s->public_id)->all();

        return [['tone' => 'info', 'icon' => 'list-check', 'title' => HintsWidget::text('forms.hints.open', $names, $count),
            'href' => $count === 1 ? "/forms/admin/{$first[0]->public_id}" : '/forms/admin?status=open']];
    }
}
