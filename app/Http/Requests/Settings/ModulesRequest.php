<?php

namespace App\Http\Requests\Settings;

use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The module switches: `modules` maps names to booleans; admin only (403 before 422). Only registered modules that can
 * be switched are accepted (no core module, no unknown name). The resulting state must keep every installed dependency
 * of a switched-on or core module on (ModuleRegistry::unmetDependencies): switching off a needed module names its
 * dependants, switching on a module whose dependency is off names the dependency.
 */
class ModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['modules' => ['required', 'array', 'max:100'], 'modules.*' => ['boolean']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $registry = app(ModuleRegistry::class);
            $all = $registry->all();
            $current = $registry->switches();
            $state = $current;
            foreach ($this->wanted() as $name => $on) {
                if (! array_key_exists($name, $current)) {
                    $validator->errors()->add('modules', __('settings.modules.unknown'));

                    return;
                }
                $state[$name] = $on;
            }

            $label = fn (string $name) => __($all[$name]->label_key);
            $dependants = [];
            foreach ($registry->unmetDependencies($state) as [$name, $dependency]) {
                if (($current[$dependency] ?? false) || $all[$name]->core) {
                    $dependants[$dependency][] = $label($name);
                } else {
                    $validator->errors()->add("modules.{$name}", __('settings.modules.requires', ['module' => $label($name), 'dependency' => $label($dependency)]));
                }
            }
            foreach ($dependants as $dependency => $names) {
                $validator->errors()->add("modules.{$dependency}", __('settings.modules.needed', ['module' => $label($dependency), 'dependants' => implode(', ', $names)]));
            }
        }];
    }

    /** @return array<string, bool> the sent switches, typed */
    public function wanted(): array
    {
        return array_map(fn (mixed $on) => (bool) $on, (array) $this->input('modules'));
    }
}
