<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service;

use BcBackup\Model\Table\BcBackupConfigsTable;
use BcBackup\Utility\BcBackupUtil;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * BcBackupConfigsService
 *
 * 設定はキーバリューテーブル bc_backup_configs に保存する。
 * 未保存のキーはデフォルト値で補完する。
 */
class BcBackupConfigsService implements BcBackupConfigsServiceInterface
{

    /**
     * キャッシュ用 Entity
     * @var \Cake\Datasource\EntityInterface|null
     */
    protected $entity = null;

    /**
     * BcBackupConfigs Table
     * @var BcBackupConfigsTable|Table
     */
    public BcBackupConfigsTable|Table $BcBackupConfigs;

    /**
     * constructor.
     */
    public function __construct()
    {
        $this->BcBackupConfigs = TableRegistry::getTableLocator()->get('BcBackup.BcBackupConfigs');
    }

    /**
     * デフォルト設定を取得
     *
     * 保存先はドキュメントルート外（ROOT/backup/）を既定とする。
     * ネイティブコマンドパスは自動検出した結果を初期値とする。
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            // 保存先フォルダ（絶対パス）
            'save_path' => ROOT . DS . 'backup',
            // DBバックアップ方式（standard: コアAPI / native: mysqldump 等）
            'db_method' => 'standard',
            // ネイティブコマンドパス
            'mysqldump_path' => BcBackupUtil::detectCommand(['mysqldump', 'mariadb-dump']),
            'pg_dump_path' => BcBackupUtil::detectCommand(['pg_dump']),
            // バックアップ対象ディレクトリ（ROOT からの相対パス・改行区切り）
            'targets' => str_replace(ROOT . DS, '', WWW_ROOT) . 'files',
            // 差分検知モード（fast: 更新日時＋サイズ / strict: ハッシュ比較）
            'diff_mode' => 'fast',
            // 保持世代数（チェーン数。0 で無制限）
            'keep_generations' => '5',
            // 保持日数（0 で無制限）
            'keep_days' => '0',
            // バックアップ中の自動メンテナンスモード切替
            'use_maintenance_mode' => '1',
            // ネイティブ方式でのトランザクション整合オプション（--single-transaction 等）
            'use_single_transaction' => '1',
        ];
    }

    /**
     * バックアップ設定を取得
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function get()
    {
        if (!$this->entity) {
            $this->entity = $this->BcBackupConfigs->newEntity(
                $this->getConfig(),
                ['validate' => 'keyValue']
            );
        }
        return $this->entity;
    }

    /**
     * バックアップ設定を配列で取得（デフォルト値マージ済み）
     *
     * @return array
     */
    public function getConfig(): array
    {
        $saved = $this->BcBackupConfigs->getKeyValue();
        if (!is_array($saved)) $saved = [];
        return array_merge($this->getDefaultConfig(), $saved);
    }

    /**
     * バックアップ設定を更新する
     *
     * @param array $postData
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function update(array $postData)
    {
        // キーバリュー保存対象を許可リストで絞り込む
        $postData = array_intersect_key($postData, $this->getDefaultConfig());
        // チェックボックスの未送信を明示的な 0 に補正
        foreach(['use_maintenance_mode', 'use_single_transaction'] as $key) {
            if (!isset($postData[$key])) $postData[$key] = '0';
        }
        $config = $this->BcBackupConfigs->newEntity($postData, ['validate' => 'keyValue']);
        if ($config->hasErrors()) {
            return $config;
        }
        if ($this->BcBackupConfigs->saveKeyValue($config->toArray())) {
            $this->entity = null;
            return $this->get();
        }
        return false;
    }

}
