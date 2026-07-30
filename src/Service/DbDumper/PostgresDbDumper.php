<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service\DbDumper;

use BaserCore\Error\BcException;
use BaserCore\Utility\BcUtil;
use BcBackup\Utility\BcBackupUtil;

/**
 * PostgresDbDumper
 *
 * pg_dump によるネイティブダンプ。
 *
 * - 認証情報は環境変数 PGPASSWORD で渡す（コマンドライン引数に載せない）
 * - リストア手順を psql で完結できるよう、プレーン SQL 形式（-Fp）で取得する
 */
class PostgresDbDumper implements DbDumperInterface
{

    /**
     * pg_dump コマンドパス
     * @var string
     */
    protected string $commandPath;

    /**
     * constructor.
     *
     * @param string $commandPath pg_dump コマンドパス
     */
    public function __construct(string $commandPath)
    {
        $this->commandPath = $commandPath;
    }

    /**
     * この方式が現在の環境で利用可能かどうか
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return BcBackupUtil::canExec() && $this->commandPath && is_executable($this->commandPath);
    }

    /**
     * DB バックアップを作業ディレクトリへ出力する
     *
     * @param string $workDir
     * @param callable|null $onProgress 未使用（外部コマンドのため進捗を取得できない）
     * @return array
     */
    public function dump(string $workDir, ?callable $onProgress = null): array
    {
        $config = BcUtil::getCurrentDbConfig();
        $path = rtrim($workDir, DS) . DS;
        if (!is_dir($path)) mkdir($path, 0777, true);
        $outFile = $path . 'dump.sql';

        $args = [
            $this->commandPath,
            '-h', (string)$config['host'],
            '-U', (string)$config['username'],
            '-Fp',
            '--no-owner',
            '--no-privileges',
            '-f', $outFile,
        ];
        if (!empty($config['port'])) {
            array_splice($args, 3, 0, ['-p', (string)(int)$config['port']]);
        }
        $args[] = (string)$config['database'];

        $result = BcBackupUtil::exec($args, ['PGPASSWORD' => (string)$config['password']]);
        if ($result['code'] !== 0) {
            throw new BcException(__d('baser_core', 'pg_dump の実行に失敗しました。{0}', $result['stderr']));
        }
        return [
            'format' => 'pg_dump',
            'file' => 'dump.sql',
        ];
    }

}
