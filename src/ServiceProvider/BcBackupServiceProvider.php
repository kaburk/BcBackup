<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\ServiceProvider;

use BcBackup\Service\BcBackupConfigsService;
use BcBackup\Service\BcBackupConfigsServiceInterface;
use BcBackup\Service\BcBackupService;
use BcBackup\Service\BcBackupServiceInterface;
use Cake\Core\ServiceProvider;

/**
 * BcBackupServiceProvider
 */
class BcBackupServiceProvider extends ServiceProvider
{

    /**
     * Provides
     * @var string[]
     */
    protected array $provides = [
        BcBackupConfigsServiceInterface::class,
        BcBackupServiceInterface::class,
    ];

    /**
     * Services
     * @param \Cake\Core\ContainerInterface $container
     */
    public function services($container): void
    {
        $container->defaultToShared(true);
        $container->add(BcBackupConfigsServiceInterface::class, BcBackupConfigsService::class);
        $container->add(BcBackupServiceInterface::class, BcBackupService::class);
    }

}
