<?php

namespace App\Install;

use RuntimeException;

final class AlreadyInstalled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('install.already_installed'));
    }
}
