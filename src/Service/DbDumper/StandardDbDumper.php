<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service\DbDumper;

use BaserCore\Error\BcException;
use BaserCore\Service\BcDatabaseServiceInterface;
use BaserCore\Utility\BcContainerTrait;
use BaserCore\Utility\BcUtil;
use Cake\Core\Plugin;
use Cake\ORM\TableRegistry;

/**
 * StandardDbDumper
 *
 * コア API（BcDatabaseService）によるスキーマ PHP＋CSV 出力。
 * 外部コマンド不要で MySQL / PostgreSQL / SQLite すべてに対応する標準方式。
 *
 * コアの UtilitiesService::backupDb() は共有の TMP/schema/ を使用するため
 * 再利用せず、同等の処理を独自の作業ディレクトリに対して行う
 * （コア標準の手動バックアップとの並走衝突を避ける）。
 */
class StandardDbDumper implements DbDumperInterface
{

    /**
     * Trait
     */
    use BcContainerTrait;

    /**
     * この方式が現在の環境で利用可能かどうか
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * DB バックアップを作業ディレクトリへ出力する
     *
     * @param string $workDir
     * @param callable|null $onProgress
     * @return array
     */
    public function dump(string $workDir, ?callable $onProgress = null): array
    {
        set_time_limit(0);
        /* @var \BaserCore\Service\BcDatabaseService $dbService */
        $dbService = $this->getService(BcDatabaseServiceInterface::class);

        $db = TableRegistry::getTableLocator()->get('BaserCore.App')->getConnection();
        $tables = $db->getSchemaCollection()->listTables();
        $dbService->clearAppTableList();
        $tableList = $dbService->getAppTableList();
        $prefix = BcUtil::getCurrentDbConfig()['prefix'];

        $path = rtrim($workDir, DS) . DS;
        if (!is_dir($path)) mkdir($path, 0777, true);

        // 進捗表示用に対象テーブル総数を数えておく
        $total = 0;
        foreach(Plugin::loaded() as $plugin) {
            if (!isset($tableList[$plugin])) continue;
            $total += count(array_intersect($tables, $tableList[$plugin]));
        }
        $done = 0;

        foreach(Plugin::loaded() as $plugin) {
            if (!isset($tableList[$plugin])) continue;
            foreach($tables as $table) {
                if (!in_array($table, $tableList[$plugin])) continue;
                $baredTable = preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $table);
                if (!$dbService->writeSchema($baredTable, [
                    'path' => $path,
                    'prefix' => $prefix
                ])) {
                    throw new BcException(__d('baser_core', 'スキーマの出力に失敗しました。テーブル: {0}', $table));
                }
                if (!$dbService->writeCsv($table, [
                    'path' => $path . $baredTable . '.csv',
                    'encoding' => 'UTF-8'
                ])) {
                    throw new BcException(__d('baser_core', 'CSV の出力に失敗しました。テーブル: {0}', $table));
                }
                $done++;
                if ($onProgress) $onProgress($done, $total);
            }
        }
        return [
            'format' => 'standard',
            'encoding' => 'UTF-8',
        ];
    }

}
