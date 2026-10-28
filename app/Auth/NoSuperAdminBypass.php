<?php

namespace App\Auth;

/**
 * Marks a policy whose checks super admins do not pass automatically (Gate::before returns null for it, see
 * AppServiceProvider::superAdminBypasses). For the module Mail: no account reads another one's mail.
 */
interface NoSuperAdminBypass {}
