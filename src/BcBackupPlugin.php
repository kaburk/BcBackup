<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup;

use BaserCore\BcPlugin;
use BcBackup\ServiceProvider\BcBackupServiceProvider;
use Cake\Core\ContainerInterface;

/**
 * plugin for BcBackup
 */
class BcBackupPlugin extends BcPlugin
{

    /**
     * services
     *
     * @param ContainerInterface $container
     */
    public function services(ContainerInterface $container): void
    {
        $container->addServiceProvider(new BcBackupServiceProvider());
    }

}
