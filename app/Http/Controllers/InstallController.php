<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallRequest;
use App\Install\AlreadyInstalled;
use App\Install\InstallData;
use App\Install\Installer;
use App\Install\Requirements;
use App\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InstallController extends Controller
{
    public function show(ModuleRegistry $modules): Response
    {
        return Inertia::render('Install/Index', [
            'requirements' => Requirements::check(),
            'modules' => $modules->switchable(),
            'preselected' => array_values(array_filter($modules->switchable(), fn (string $m) => $modules->all()[strtolower($m)]->default_on)),
            'locales' => config('ignis.locales'),
            'tokenRequired' => Installer::requiredToken() !== null,
            'redirectUri' => rtrim((string) config('app.url'), '/').'/auth/discord/callback',
        ]);
    }

    public function store(InstallRequest $request, Installer $installer): RedirectResponse
    {
        try {
            $installer->run(InstallData::fromValidated($request->validated()));
        } catch (AlreadyInstalled) {
            abort(404);
        }

        return redirect('/');
    }
}
