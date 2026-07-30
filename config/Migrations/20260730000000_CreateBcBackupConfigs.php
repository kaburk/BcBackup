<?php
declare(strict_types=1);

use BaserCore\Database\Migration\BcMigration;

class CreateBcBackupConfigs extends BcMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up()
    {
        $this->table('bc_backup_configs', [
            'collation' => 'utf8mb4_general_ci'
        ])
            ->addColumn('name', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('value', 'text', [
                'default' => null,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down()
    {
        $this->table('bc_backup_configs')->drop()->save();
    }
}
