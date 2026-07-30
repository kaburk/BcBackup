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
 * MysqlDbDumper
 *
 * mysqldump / mariadb-dump によるネイティブダンプ。
 *
 * - 認証情報は ps で漏洩しないよう、一時ファイル（パーミッション 600）に書いて
 *   --defaults-extra-file で渡す（実行後に即削除）
 * - MySQL 8 系のクライアント／サーバ不一致時の column-statistics エラーは
 *   オプションを付けてリトライする
 * - GTID 有効環境向けに --set-gtid-purged=OFF を試行し、未対応
 *   （mariadb-dump 等）ならオプションなしでリトライする
 */
class MysqlDbDumper implements DbDumperInterface
{

    /**
     * mysqldump コマンドパス
     * @var string
     */
    protected string $commandPath;

    /**
     * --single-transaction を付与するか
     * @var bool
     */
    protected bool $singleTransaction;

    /**
     * constructor.
     *
     * @param string $commandPath mysqldump コマンドパス
     * @param bool $singleTransaction --single-transaction を付与するか
     */
    public function __construct(string $commandPath, bool $singleTransaction = true)
    {
        $this->commandPath = $commandPath;
        $this->singleTransaction = $singleTransaction;
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

        // 認証情報ファイル（600）を作成
        $credentialFile = BcBackupUtil::getWorkDir() . 'mysql_credential_' . getmypid() . '.cnf';
        $credential = "[client]\n"
            . 'user = "' . addcslashes((string)$config['username'], '"\\') . "\"\n"
            . 'password = "' . addcslashes((string)$config['password'], '"\\') . "\"\n"
            . 'host = "' . addcslashes((string)$config['host'], '"\\') . "\"\n";
        if (!empty($config['port'])) {
            $credential .= 'port = ' . (int)$config['port'] . "\n";
        }
        if (file_put_contents($credentialFile, $credential) === false) {
            throw new BcException(__d('baser_core', '認証情報ファイルの作成に失敗しました。'));
        }
        chmod($credentialFile, 0600);

        try {
            $baseArgs = [$this->commandPath, '--defaults-extra-file=' . $credentialFile];
            if ($this->singleTransaction) {
                // InnoDB のみ整合が保証される。MyISAM 混在時はメンテナンスモード併用が保険となる
                $baseArgs[] = '--single-transaction';
            }
            // クライアント実装の差異をエラー内容から検出して適応リトライする
            // - set-gtid-purged: mariadb-dump では未対応のため外す
            // - column-statistics: MySQL 8 系のクライアント／サーバ不一致で発生
            // - TLS/SSL: MariaDB 11.4+ クライアントは証明書検証がデフォルト有効
            $options = ['--set-gtid-purged=OFF'];
            $lastError = '';
            for($attempt = 0; $attempt < 4; $attempt++) {
                $args = array_merge($baseArgs, $options, ['--result-file=' . $outFile, (string)$config['database']]);
                $result = BcBackupUtil::exec($args);
                if ($result['code'] === 0) {
                    return [
                        'format' => 'mysqldump',
                        'file' => 'dump.sql',
                    ];
                }
                $lastError = $result['stderr'];
                if (str_contains($lastError, 'set-gtid-purged') && in_array('--set-gtid-purged=OFF', $options, true)) {
                    $options = array_values(array_diff($options, ['--set-gtid-purged=OFF']));
                } elseif ((str_contains($lastError, 'column-statistics') || str_contains($lastError, 'column_statistics'))
                    && !in_array('--column-statistics=0', $options, true)) {
                    $options[] = '--column-statistics=0';
                } elseif ((str_contains($lastError, 'TLS/SSL') || str_contains($lastError, 'Certificate verification'))
                    && !in_array('--skip-ssl', $options, true)) {
                    $options[] = '--skip-ssl';
                } else {
                    break;
                }
            }
            throw new BcException(__d('baser_core', 'mysqldump の実行に失敗しました。{0}', $lastError));
        } finally {
            @unlink($credentialFile);
        }
    }

}
