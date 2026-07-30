<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service;

/**
 * BcBackupConfigsServiceInterface
 */
interface BcBackupConfigsServiceInterface
{

    /**
     * バックアップ設定を取得
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function get();

    /**
     * バックアップ設定を配列で取得（デフォルト値マージ済み）
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * バックアップ設定を更新する
     *
     * @param array $postData
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function update(array $postData);

}
