<?php

namespace App\Update;

/**
 * Starts an update run in the background and tells whether one is alive. Files installation: ProcessUpdateStarter (detached
 * artisan process); Docker: DockerDriver (asks the updater container). Bound in AppServiceProvider by
 * Installation::driver().
 */
interface UpdateStarter
{
    /** @throws UpdateException */
    public function start(string $version, bool $skipTrial = false): void;

    /** @throws UpdateException */
    public function rollback(): void;

    /** Is an update process alive (holds the lock)? When this cannot be told, true: a start is refused, never doubled. */
    public function ownerAlive(): bool;
}
