<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PersonnelCardController;
use App\Http\Controllers\Api\ServiceNumberController;
use App\Http\Controllers\Api\VehicleCardController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Controllers\Auth\InviteRedemptionController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentPdfController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvitationCodeController;
use App\Http\Controllers\LegacyDocumentController;
use App\Http\Controllers\LoadoutController;
use App\Http\Controllers\OwnDocumentsController;
use App\Http\Controllers\OwnProtocolsController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\PersonnelInvitationController;
use App\Http\Controllers\PersonnelProfileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\DashboardSettingsController;
use App\Http\Controllers\Settings\DiscordSettingsController;
use App\Http\Controllers\Settings\DiscordWebhookController;
use App\Http\Controllers\Settings\DocumentTemplateController;
use App\Http\Controllers\Settings\EmsQualificationController;
use App\Http\Controllers\Settings\FireQualificationController;
use App\Http\Controllers\Settings\LogoController;
use App\Http\Controllers\Settings\ModulesController;
use App\Http\Controllers\Settings\PersonnelTitleController;
use App\Http\Controllers\Settings\PoiController;
use App\Http\Controllers\Settings\RankController;
use App\Http\Controllers\Settings\ScheduledJobsController;
use App\Http\Controllers\Settings\SpecialtyController;
use App\Http\Controllers\Settings\SystemConfigController;
use App\Http\Controllers\Settings\SystemOverviewController;
use App\Http\Controllers\Settings\UpdateController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\TabletLoginController;
use App\Http\Controllers\TacticalSymbolTemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDefectController;
use App\Http\Controllers\VehicleRegistryController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', HomeController::class)->middleware('auth');

Route::middleware('guest')->group(function () {
    Route::get('/login', [DiscordLoginController::class, 'login'])->name('login');
    Route::middleware('throttle:20,1,discord-login')->group(function () {
        Route::get('/auth/discord', [DiscordLoginController::class, 'redirect']);
        Route::get('/auth/discord/callback', [DiscordLoginController::class, 'callback']);
        Route::get('/auth/callback', [DiscordLoginController::class, 'callback']);
        Route::match(['get', 'post'], '/invite', InviteRedemptionController::class);
    });
});
Route::middleware('auth')->prefix('users/roles')->controller(RoleController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::put('/{role}', 'update');
    Route::delete('/{role}', 'destroy');
});
Route::middleware('auth')->prefix('users/invitations')->controller(InvitationCodeController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::delete('/{code}', 'destroy')->whereNumber('code');
});
Route::get('/users/audit-log', [AuditLogController::class, 'index'])->middleware('auth');
Route::middleware('auth')->prefix('users')->controller(UserController::class)->group(function () {
    Route::get('/', 'index')->name('users.index');
    Route::get('/{user}/edit', 'edit')->whereNumber('user');
    Route::put('/{user}', 'update')->whereNumber('user');
    Route::post('/{user}/toggle-active', 'toggleActive')->whereNumber('user');
    Route::post('/{user}/personnel', 'linkPersonnel')->whereNumber('user');
    Route::delete('/{user}', 'destroy')->whereNumber('user');
});
Route::middleware('auth')->prefix('personnel')->controller(PersonnelController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::post('/', 'store');
    Route::delete('/{personnel}', 'destroy')->whereNumber('personnel');
});
Route::middleware('auth')->prefix('personnel/{personnel}')->whereNumber('personnel')->controller(PersonnelProfileController::class)->group(function () {
    Route::get('/', 'show');
    Route::put('/', 'update');
    Route::put('/specialties', 'updateSpecialties');
    Route::post('/picture', 'uploadPicture')->middleware('throttle:20,1,picture');
    Route::delete('/picture', 'deletePicture')->middleware('throttle:20,1,picture');
    Route::post('/notes', 'addNote');
    Route::delete('/notes/{entry}', 'deleteNote')->where('entry', '[0-9]{1,18}');
});
Route::middleware(['auth', 'throttle:30,1,profile-write'])->prefix('profile')->controller(ProfileController::class)->group(function () {
    Route::post('/theme', 'theme');
    Route::post('/discord-dm', 'discordDm');
});
Route::post('/personnel/{personnel}/invite', PersonnelInvitationController::class)->whereNumber('personnel')->middleware(['auth', 'throttle:30,1,invite']);
Route::middleware(['auth', 'throttle:60,1,service-number'])->prefix('api/personnel')->controller(ServiceNumberController::class)->group(function () {
    Route::post('/check-dienstnr', 'check');
    Route::post('/check-dienstnr-legacy', 'checkLegacy');
});
Route::middleware(['auth', 'throttle:120,1,cards'])->prefix('api')->controller(PersonnelCardController::class)->group(function () {
    Route::get('/personnel/by-dienstnr/{number}/card', 'byServiceNumber');
    Route::get('/personnel/{id}/card', 'personnel');
    Route::get('/users/{id}/card', 'user');
});
Route::middleware('auth')->prefix('vehicles/defects')->whereNumber('defect')->controller(VehicleDefectController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::get('/{defect}', 'show');
    Route::middleware('throttle:30,1,vehicle-defect-write')->group(function () {
        Route::patch('/{defect}', 'update');
        Route::post('/{defect}/resolve', 'resolve');
        Route::delete('/{defect}', 'destroy');
    });
});
Route::middleware('auth')->prefix('vehicles/registry')->whereNumber('entry')->controller(VehicleRegistryController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/status', 'status')->middleware('throttle:60,1,vehicle-registry-poll');
    Route::middleware('throttle:60,1,vehicle-registry-write')->group(function () {
        Route::post('/request', 'requestRegistry');
        Route::post('/cancel-request', 'cancelRequest');
        Route::post('/ignore-all', 'ignoreAll');
        Route::post('/{entry}/{action}', 'decide')->whereIn('action', ['import', 'overwrite', 'merge', 'ignore']);
    });
});
Route::post('/vehicles/{vehicle}/defects', [VehicleDefectController::class, 'store'])->whereNumber('vehicle')->middleware(['auth', 'throttle:30,1,vehicle-defect-write']);
Route::middleware('auth')->prefix('vehicles')->controller(VehicleController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::middleware('throttle:30,1,vehicle-write')->group(function () {
        Route::post('/', 'store');
        Route::post('/bulk', 'bulk');
        Route::put('/{vehicle}', 'update')->whereNumber('vehicle');
        Route::delete('/{vehicle}', 'destroy')->whereNumber('vehicle');
    });
    Route::get('/{vehicle}', 'show')->whereNumber('vehicle');
    Route::get('/{vehicle}/edit', 'edit')->whereNumber('vehicle');
});
Route::middleware(['auth', 'throttle:60,1,poi-settings'])->prefix('settings/pois')->whereNumber('poi')->controller(PoiController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::put('/{poi}', 'update');
    Route::delete('/{poi}', 'destroy');
});
Route::middleware('auth')->prefix('settings/vehicles/loadouts')->whereNumber(['category', 'item'])->controller(LoadoutController::class)->group(function () {
    Route::get('/', 'index');
    Route::middleware('throttle:60,1,loadout-write')->group(function () {
        Route::post('/categories', 'storeCategory');
        Route::put('/categories/{category}', 'updateCategory');
        Route::delete('/categories/{category}', 'destroyCategory');
        Route::post('/categories/{category}/items', 'storeItem');
        Route::post('/items/reorder', 'reorder');
        Route::match(['put', 'patch'], '/items/{item}', 'updateItem');
        Route::delete('/items/{item}', 'destroyItem');
    });
});
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
Route::middleware('auth')->prefix('settings/dashboard')->whereNumber(['category', 'link'])->controller(DashboardSettingsController::class)->group(function () {
    Route::get('/', 'index');
    Route::middleware('throttle:30,1,dashboard-write')->group(function () {
        Route::post('/categories', 'storeCategory');
        Route::post('/categories/reorder', 'reorderCategories');
        Route::put('/categories/{category}', 'updateCategory');
        Route::delete('/categories/{category}', 'destroyCategory');
        Route::post('/links', 'storeLink');
        Route::post('/links/reorder', 'reorderLinks');
        Route::put('/links/{link}', 'updateLink');
        Route::delete('/links/{link}', 'destroyLink');
    });
});
Route::middleware('auth')->prefix('settings/vehicles/symbol-templates')->whereNumber('template')->controller(TacticalSymbolTemplateController::class)->group(function () {
    Route::get('/', 'index');
    Route::middleware('throttle:60,1,symbol-template-write')->group(function () {
        Route::post('/', 'store');
        Route::put('/{template}', 'update');
        Route::delete('/{template}', 'destroy');
        Route::post('/{template}/apply', 'apply');
    });
});
Route::get('/api/vehicles/{id}/card', [VehicleCardController::class, 'show'])->middleware(['auth', 'throttle:120,1,vehicle-card']);
foreach ([
    'ranks' => RankController::class,
    'fire-qualifications' => FireQualificationController::class,
    'ems-qualifications' => EmsQualificationController::class,
    'specialties' => SpecialtyController::class,
    'titles' => PersonnelTitleController::class,
] as $path => $controller) {
    Route::middleware('auth')->prefix("settings/personnel/{$path}")->controller($controller)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{id}', 'update')->where('id', '[0-9]{1,18}');
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]{1,18}');
    });
}
Route::middleware('auth')->prefix('settings/system')->group(function () {
    Route::get('/', [SystemOverviewController::class, 'index']);
    Route::get('/config', [SystemConfigController::class, 'index']);
    Route::put('/config/{group}', [SystemConfigController::class, 'update'])->middleware('throttle:30,1,system-config-write');
    Route::get('/api-key', [ApiKeyController::class, 'show'])->middleware('throttle:30,1,api-key-read');
    Route::post('/api-key', [ApiKeyController::class, 'regenerate'])->middleware('throttle:5,1,api-key-write');
    Route::post('/logo', [LogoController::class, 'store'])->middleware('throttle:30,1,settings-write');
    Route::delete('/logo', [LogoController::class, 'destroy'])->middleware('throttle:30,1,settings-write');
    Route::get('/modules', [ModulesController::class, 'index']);
    Route::put('/modules', [ModulesController::class, 'update'])->middleware('throttle:30,1,settings-write');
    Route::get('/discord', [DiscordSettingsController::class, 'index']);
    Route::put('/discord', [DiscordSettingsController::class, 'update'])->middleware('throttle:30,1,settings-write');
    Route::delete('/discord', [DiscordSettingsController::class, 'destroy'])->middleware('throttle:30,1,settings-write');
    Route::post('/discord/test', [DiscordSettingsController::class, 'test'])->middleware('throttle:30,1,settings-write');
    // Update by click. The status and rollback paths are excepted from the maintenance block in bootstrap/app.php; keep both in sync.
    Route::controller(UpdateController::class)->prefix('/update')->group(function () {
        Route::get('/status', 'status');
        Route::get('/backups', 'backups');
        Route::middleware('throttle:6,1,update-github')->group(function () {
            Route::post('/check', 'check');
            Route::post('/', 'start');
        });
        Route::post('/rollback', 'rollback')->middleware('throttle:30,1,settings-write');
    });
    Route::get('/jobs', [ScheduledJobsController::class, 'index']);
    Route::middleware('throttle:30,1,settings-write')->controller(ScheduledJobsController::class)->prefix('/jobs')->group(function () {
        Route::post('/run', 'run');
        Route::delete('/failed', 'destroyAll');
        Route::post('/failed/{id}/retry', 'retry')->where('id', '[A-Za-z0-9-]{1,64}');
        Route::delete('/failed/{id}', 'destroy')->where('id', '[A-Za-z0-9-]{1,64}');
    });
    Route::controller(DiscordWebhookController::class)->prefix('/discord/webhooks/{kind}')->middleware('throttle:30,1,settings-write')->group(function () {
        Route::put('/', 'update');
        Route::delete('/', 'destroy');
        Route::post('/test', 'test');
    });
});
Route::inertia('/settings', 'Settings/Index')->middleware('auth');
Route::redirect('/settings/organisation', '/settings/system/config?tab=organisation', 301);
Route::middleware('auth')->prefix('settings/documents/editor-templates')->whereNumber('template')->controller(DocumentTemplateController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::post('/create', 'store')->middleware('throttle:30,1,document-template-write');
    Route::get('/{template}', 'edit');
    Route::put('/{template}', 'update')->middleware('throttle:30,1,document-template-write');
    Route::delete('/{template}', 'destroy')->middleware('throttle:30,1,document-template-write');
    Route::post('/{template}/deactivate', 'deactivate')->middleware('throttle:30,1,document-template-write');
    Route::post('/{template}/reactivate', 'reactivate')->middleware('throttle:30,1,document-template-write');
});
Route::post('/personnel/{personnel}/documents', [DocumentController::class, 'store'])->whereNumber('personnel')->middleware('auth');
Route::middleware('auth')->prefix('documents/{document}')->whereNumber('document')->controller(DocumentController::class)->group(function () {
    Route::get('/', 'show');
    Route::get('/edit', 'edit');
    Route::post('/save', 'save')->middleware('throttle:120,1,document-save');
    Route::post('/issue', 'issue')->middleware('throttle:20,1,document-issue');
    Route::delete('/', 'destroy');
});
Route::middleware('auth')->controller(LegacyDocumentController::class)->group(function () {
    Route::get('/personnel/document-view', 'show');
    Route::post('/personnel/document-delete', 'destroy')->middleware('throttle:30,1,legacy-document-write');
    Route::get('/api/documents/get-document', 'data')->middleware('throttle:120,1,cards');
    Route::post('/api/documents/archive', 'archive')->middleware('throttle:30,1,legacy-document-write');
});
Route::get('/me/documents', OwnDocumentsController::class)->middleware('auth');
Route::get('/me/protocols', OwnProtocolsController::class)->middleware('auth');
Route::middleware('auth')->prefix('api/notifications')->controller(NotificationController::class)->group(function () {
    Route::get('/poll', 'poll')->middleware('throttle:20,1,notifications-poll');
    Route::middleware('throttle:60,1,notifications-write')->group(function () {
        Route::post('/mark-read', 'markRead');
        Route::post('/mark-all-read', 'markAllRead');
    });
});
Route::middleware('auth')->prefix('inbox')->controller(InboxController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/popover', 'popover')->middleware('throttle:60,1,notifications-popover');
    Route::get('/{id}/open', 'open')->whereUuid('id');
    Route::post('/read', 'read')->middleware('throttle:60,1,notifications-write');
});
Route::post('/api/announcements/dismiss', [AnnouncementController::class, 'dismiss'])->middleware(['auth', 'throttle:30,1,announcement-dismiss']);
Route::get('/api/system/global-search', GlobalSearchController::class)->middleware(['auth', 'throttle:60,1,global-search']);
Route::get('/documents/{document}/pdf', DocumentPdfController::class)->whereNumber('document')->middleware('auth');
// Legacy PDF links stay public, the controller admits only imported XXXX-XXXX-XXXX.pdf names.
Route::get('/storage/documents/{file}', [StorageFileController::class, 'legacyPdf'])
    ->middleware('throttle:120,1,legacy-pdf')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EnsureActiveUser::class, SetLocale::class, HandleInertiaRequests::class]);
// The logo is public too (login page); only the current file, no session.
Route::get('/branding/{file}', BrandingController::class)
    ->where('file', '[a-f0-9]{32}\.(png|jpg|webp)')
    ->middleware('throttle:120,1,branding')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EnsureActiveUser::class, SetLocale::class, HandleInertiaRequests::class]);
// Private uploads under their legacy URLs; area and file name are checked by the controller.
// Never /storage/kb-images/*: that area belongs to the KnowledgeBase module (public with its switch), whatever the route order.
Route::get('/storage/{area}/{file}', StorageFileController::class)->where('area', '(?!kb-images/)[^/]+')->middleware('auth');
// ef_bridge one-time token (issued by POST /api/tablet/login-token). Not `guest`: the tablet may switch accounts.
Route::get('/auth/tablet', [TabletLoginController::class, 'login'])->middleware(['throttle:30,1,tablet-login', 'fivem']);
Route::post('/logout', [DiscordLoginController::class, 'logout'])->middleware('auth');

// Open only while not installed (EnsureInstalled answers 404 afterwards).
Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'store'])->middleware('throttle:10,1,install');

// Legacy monitoring URL. Same handler as /api/health. This file is wrapped in the `web`
// group, so it is excluded here and the stateless `api` group used instead: no session
// is started (DB may be down) and no CSRF applies.
Route::withoutMiddleware('web')->middleware('api')->get('/healthz', [HealthController::class, 'index']);
