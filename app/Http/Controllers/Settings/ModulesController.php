<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ModulesRequest;
use App\Modules\ModuleInfo;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module switches (legacy Einstellungen › System › Module): the registered modules that are not core, with immediate
 * effect (no restart). Only modules that change are written and audited.
 */
class ModulesController extends Controller
{
    public function index(ModuleRegistry $registry): Response
    {
        $this->authorize('system.admin');
        $all = $registry->all();

        return Inertia::render('Settings/System/Modules', [
            'modules' => array_values(array_map(fn (ModuleInfo $m) => [
                'name' => $m->name,
                'label' => __($m->label_key),
                'description' => $m->description_key === '' ? '' : __($m->description_key),
                'group' => $m->group,
                'enabled' => $registry->isEnabled($m->name),
                'depends' => array_values(array_map(fn (string $d) => __($all[$d]->label_key), array_filter($m->depends, fn (string $d) => isset($all[$d])))),
            ], array_filter($all, fn (ModuleInfo $m) => ! $m->core))),
        ]);
    }

    public function update(ModulesRequest $request, ModuleRegistry $registry, Settings $settings): RedirectResponse
    {
        foreach ($request->wanted() as $name => $on) {
            if ($registry->isEnabled($name) === $on) {
                continue;
            }
            $settings->set("modules.{$name}.enabled", $on);
            AuditLogger::log($request->user(), $on ? 'Modul aktiviert' : 'Modul deaktiviert', $name, 'System', true, ['module' => $name]);
        }

        return redirect('/settings/system/modules')->with('status', 'settings.modules.saved');
    }
}
