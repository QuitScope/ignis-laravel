<?php

namespace App\Http\Requests;

use App\Auth\Permissions;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public const COLORS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info'];

    public function authorize(): bool
    {
        $role = $this->route('role');

        return (bool) $this->user()?->can($role instanceof Role ? 'update' : 'create', $role instanceof Role ? $role : Role::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('role');
        $allowed = array_merge(
            array_keys(Permissions::catalog()),
            $current instanceof Role ? $current->permissions()->pluck('name')->all() : [],
        );

        return [
            'name' => ['required', 'string', 'max:255', function ($attr, $value, $fail) use ($current) {
                $taken = Role::where('guard_name', 'web')
                    ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                    ->when($current instanceof Role, fn ($q) => $q->whereKeyNot($current->getKey()))
                    ->exists();
                if ($taken) {
                    $fail(trans('validation.unique', ['attribute' => $attr]));
                }
            }],
            'priority' => ['required', 'integer', 'between:1,999'],
            'color' => ['required', Rule::in(self::COLORS)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in($allowed)],
        ];
    }
}
