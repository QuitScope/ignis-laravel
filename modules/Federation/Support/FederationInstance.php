<?php

namespace Modules\Federation\Support;

use App\Support\Settings;
use Illuminate\Support\Str;

final class FederationInstance
{
    public static function instanceId(): string
    {
        $settings = app(Settings::class);
        $id = $settings->get('federation.instance_id');
        if (is_string($id) && $id !== '') {
            return $id;
        }
        $id = (string) Str::uuid();
        $settings->set('federation.instance_id', $id);

        return $id;
    }
}
