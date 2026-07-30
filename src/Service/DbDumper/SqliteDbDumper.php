<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service\DbDumper;

use BaserCore\Error\BcException;
use BaserCore\Utility\BcUtil;
use Cake\Log\LogTrait;
use PDO;
use SQLite3;

/**
 * SqliteDbDumper
 *
 * SQLite の DB ファイルバックアップ。稼働中の単純コピーは破損リスクが
 * あるため、以下の順で自動フォールバックする。
 *
 * 1. VACUUM INTO（SQLite 3.27+。整合の取れたコピーを出力）
 * 2. SQLite3::backup()（Online Backup API。SQLite 3.6.11+）
 *
 * どちらも失敗した場合は BcException を投げ、呼び出し側（BcBackupService）が
 * 標準方式（コアAPI）へフォールバックする。
 */
class SqliteDbDumper implements DbDumperInterface
{

    /**
     * Trait
     */
    use LogTrait;

    /**
     * この方式が現在の環境で利用可能かどうか
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        $config = BcUtil::getCurrentDbConfig();
        return !empty($config['database']) && is_file($config['database']);
    }

    /**
     * DB バックアップを作業ディレクトリへ出力する
     *
     * @param string $workDir
     * @param callable|null $onProgress 未使用（ファイル単位コピーのため進捗を取得できない）
     * @return array
     */
    public function dump(string $workDir, ?callable $onProgress = null): array
    {
        $config = BcUtil::getCurrentDbConfig();
        $database = (string)$config['database'];
        $path = rtrim($workDir, DS) . DS;
        if (!is_dir($path)) mkdir($path, 0777, true);
        $outFile = $path . basename($database);

        // 1. VACUUM INTO
        try {
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec("VACUUM INTO " . $pdo->quote($outFile));
            $pdo = null;
            if (is_file($outFile) && filesize($outFile) > 0) {
                return $this->buildResult($database);
            }
        } catch (\Throwable $e) {
            $this->log(__d('baser_core', 'VACUUM INTO に失敗したため SQLite3::backup() へフォールバックします。{0}', $e->getMessage()));
            @unlink($outFile);
        }

        // 2. SQLite3::backup()
        if (class_exists('SQLite3')) {
            try {
                $source = new SQLite3($database, SQLITE3_OPEN_READONLY);
                $dest = new SQLite3($outFile);
                $result = $source->backup($dest);
                $source->close();
                $dest->close();
                if ($result && is_file($outFile) && filesize($outFile) > 0) {
                    return $this->buildResult($database);
                }
            } catch (\Throwable $e) {
                @unlink($outFile);
                throw new BcException(__d('baser_core', 'SQLite のバックアップに失敗しました。{0}', $e->getMessage()));
            }
        }
        throw new BcException(__d('baser_core', 'SQLite のバックアップに失敗しました。'));
    }

    /**
     * manifest の db セクションを組み立てる
     *
     * @param string $database DB ファイルパス
     * @return array
     */
    protected function buildResult(string $database): array
    {
        return [
            'format' => 'sqlite',
            'file' => basename($database),
        ];
    }

}
