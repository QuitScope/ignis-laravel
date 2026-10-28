<?php

use Phinx\Migration\AbstractMigration;

final class AlterIntraFahrtenbuch extends AbstractMigration
{
    public function change(): void
    {
        $this->table('intra_fahrtenbuch')->addColumn('x', 'string')->update();
        $this->table('phinxlog')->update();
    }
}
