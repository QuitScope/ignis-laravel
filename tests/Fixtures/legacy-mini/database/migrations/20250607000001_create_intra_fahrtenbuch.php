<?php

use Phinx\Migration\AbstractMigration;

final class CreateIntraFahrtenbuch extends AbstractMigration
{
    public function change(): void
    {
        $this->table('intra_fahrtenbuch', ['id' => false])->create();
        if (!$this->hasTable('intra_users')) {
            $this->table("intra_users")->create();
        }
    }
}
