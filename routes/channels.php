<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Core channels. Module channels are registered by their service providers. Private only; the socket carries a pulse
// ({topic, id}), never data, and the page reloads through its policy-checked endpoints.
Broadcast::channel('user.{id}', fn (User $user, string $id) => ctype_digit($id) && $user->id === (int) $id);
