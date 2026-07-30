<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Model\Entity;

use Cake\ORM\Entity;

/**
 * バックアップ設定エンティティ（キーバリュー）
 */
class BcBackupConfig extends Entity
{

    /**
     * Accessible
     *
     * @var array
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

}
