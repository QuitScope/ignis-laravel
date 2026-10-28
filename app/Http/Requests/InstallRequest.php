<?php

namespace App\Http\Requests;

use App\Install\Installer;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstallRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::messagesFor();
    }

    /**
     * Shared with ignis:install. installToken is checked here but is not part of InstallData.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        $token = Installer::requiredToken();

        return [
            'orgName' => ['required', 'string', 'max:100'],
            'discordClientId' => ['required', 'string', 'digits_between:17,20'],
            'discordClientSecret' => ['required', 'string', 'max:255'],
            'adminDiscordId' => ['required', 'string', 'digits_between:17,20'],
            'modules' => ['nullable', 'list', function (string $attribute, mixed $value, Closure $fail) {
                $registry = app(ModuleRegistry::class);
                $known = $registry->switchable();
                foreach ((array) $value as $name) {
                    if (! is_string($name) || ! in_array($name, $known, true)) {
                        $fail(__('install.error.modules'));

                        return;
                    }
                }
                $all = $registry->all();
                foreach ($registry->unmetDependencies(array_fill_keys(array_map('strtolower', (array) $value), true)) as [$name, $dependency]) {
                    $fail(__('settings.modules.requires', ['module' => __($all[$name]->label_key), 'dependency' => __($all[$dependency]->label_key)]));
                }
            }],
            'locale' => ['required', Rule::in(config('ignis.locales'))],
            'installToken' => $token === null ? [] : ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($token) {
                if (! is_string($value) || ! hash_equals($token, $value)) {
                    $fail(__('install.error.installToken'));
                }
            }],
        ];
    }

    /** @return array<string, string> one message per field, whatever rule failed */
    public static function messagesFor(): array
    {
        return collect(['orgName', 'discordClientId', 'discordClientSecret', 'adminDiscordId', 'modules', 'locale', 'installToken'])
            ->mapWithKeys(fn (string $f) => [$f => __('install.error.'.$f)])
            ->all();
    }
}
