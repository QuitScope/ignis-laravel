<?php

namespace Modules\Mail\Listeners;

use App\Events\PersonnelDeleted;
use App\Events\PersonnelSaved;
use Modules\Mail\Support\MailboxProvisioner;

/** Keeps the mailbox of a record in line (saved: create or update; deleted: deactivate). Registered via listen(). */
class SyncMailbox
{
    public function __construct(private readonly MailboxProvisioner $provisioner) {}

    public function handle(PersonnelSaved|PersonnelDeleted $event): void
    {
        $this->provisioner->sync($event->personnelId);
    }
}
