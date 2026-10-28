<?php

namespace App\Http\Controllers;

use App\Http\Requests\LinkPersonnelRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewList', User::class);
        $actor = $this->actor();

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $users = User::query()->with('roles')
            ->when($search !== '', fn ($q) => SqlLike::anyOf($q, ['username', 'name'], $search))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('username')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'name' => $u->name,
                'role' => ($r = $u->role()) ? ['name' => $r->name, 'color' => $r->color] : null,
                'is_active' => $u->is_active,
                'created_at' => $u->created_at?->toDateString(),
                'can' => [
                    'update' => $actor->can('update', $u),
                    'delete' => $actor->can('delete', $u),
                    'toggle' => $actor->can('toggleActive', $u),
                ],
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search, 'status' => in_array($status, ['active', 'inactive'], true) ? $status : ''],
        ]);
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);
        $actor = $this->actor();

        return Inertia::render('Users/Edit', [
            'target' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'role_id' => $user->role()?->id,
                'rank' => $user->is_super_admin || $user->role() ? $user->rank() : null,
                'is_active' => $user->is_active,
            ],
            'assignableRoles' => $this->assignableRoles($actor),
            'actorRank' => $actor->rank(),
            'personnel' => $user->personnel?->only(['id', 'full_name', 'service_number']),
            'freePersonnel' => $user->personnel_id === null ? AccountLink::freePersonnel()->map->only(['id', 'full_name', 'service_number'])->all() : [],
        ]);
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $role = Role::findOrFail($request->validated('role'));
        $this->authorize('assignRole', [User::class, $role]);

        $old = $user->role()?->name;
        $user->setRole($role);
        AuditLogger::log($this->actor(), 'Benutzer aktualisiert', 'Rolle: '.($old ?? '–').' → '.$role->name, 'Benutzer', true, ['user_id' => $user->id, 'role_id' => $role->id]);

        return back();
    }

    public function linkPersonnel(LinkPersonnelRequest $request, User $user): RedirectResponse
    {
        try {
            if ($request->boolean('unlink')) {
                AccountLink::unlink($user, $this->actor());
            } else {
                $person = Personnel::query()->find($request->integer('personnel_id')) ?? throw new DomainException(__('account_link.personnel_missing'));
                AccountLink::link($user, $person, $this->actor());
            }
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['personnel_id' => $e->getMessage()]);
        }

        return back();
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $this->authorize('toggleActive', $user);

        $deactivate = $user->is_active;
        $user->is_active = ! $deactivate;
        $user->deactivated_at = $deactivate ? now() : null;
        $user->deactivated_by = $deactivate ? $this->actor()->id : null;
        $user->save();
        AuditLogger::log($this->actor(), $deactivate ? 'Benutzer deaktiviert' : 'Benutzer reaktiviert', null, 'Benutzer', true, ['user_id' => $user->id]);

        return back();
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $user->delete();
        AuditLogger::log($this->actor(), 'Benutzer endgültig gelöscht', null, 'Benutzer', true, ['user_id' => $user->id]);

        return to_route('users.index');
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** @return list<array{id: int, name: string, priority: int, color: string}> */
    private function assignableRoles(User $actor): array
    {
        return Role::where('priority', '>', $actor->rank())->orderBy('priority')->get()
            ->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'priority' => $r->priority, 'color' => $r->color])
            ->all();
    }
}
