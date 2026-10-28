<?php

namespace App\Http\Controllers;

use App\Auth\Permissions;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewList', Role::class);

        return Inertia::render('Roles/Index', [
            'roles' => Role::with('permissions:id,name')->withCount('users')->orderBy('priority')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'priority' => $r->priority,
                    'color' => $r->color,
                    'is_default' => $r->is_default,
                    'users_count' => $r->users_count,
                    'permissions' => $r->permissions->pluck('name')->reject(Permissions::hidden(...))->sort()->values(),
                ]),
            'permissionGroups' => Permissions::groups(),
            'can' => ['manage' => (bool) request()->user()?->can('create', Role::class)],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);
        $data = $request->validated();

        $role = new Role;
        $role->guard_name = 'web';
        $this->fill($role, $data);
        AuditLogger::log($request->user(), 'Rolle erstellt', 'Name: '.$role->name.self::diff([], $data['permissions']), 'Rollen', true, ['role_id' => $role->id]);

        return back();
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);
        $before = $role->permissions()->pluck('name')->all();
        $data = $request->validated();
        $data['permissions'] = array_values(array_unique([...$data['permissions'], ...array_filter($before, Permissions::hidden(...))]));
        $this->fill($role, $data);
        AuditLogger::log($request->user(), 'Rolle aktualisiert', 'Name: '.$role->name.self::diff($before, $data['permissions']), 'Rollen', true, ['role_id' => $role->id]);

        return back();
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        if ($role->is_default) {
            throw ValidationException::withMessages(['role' => trans('roles.error.default')]);
        }
        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => trans('roles.error.in_use')]);
        }
        $role->delete();
        AuditLogger::log(request()->user(), 'Rolle gelöscht', 'Name: '.$role->name, 'Rollen', true, ['role_id' => $role->id]);

        return back();
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private static function diff(array $before, array $after): string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        return ($added === [] ? '' : '; hinzugefügt: '.implode(', ', $added))
            .($removed === [] ? '' : '; entfernt: '.implode(', ', $removed));
    }

    /** @param array<string, mixed> $data validated input; is_default is deliberately not editable here */
    private function fill(Role $role, array $data): void
    {
        DB::transaction(function () use ($role, $data) {
            $role->name = $data['name'];
            $role->priority = $data['priority'];
            $role->color = $data['color'];
            $role->save();
            $role->syncPermissions(array_map(
                fn (string $n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']),
                $data['permissions'],
            ));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
