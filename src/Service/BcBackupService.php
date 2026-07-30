<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service;

use BaserCore\Error\BcException;
use BaserCore\Service\BcDatabaseServiceInterface;
use BaserCore\Service\SiteConfigsServiceInterface;
use BaserCore\Utility\BcContainerTrait;
use BaserCore\Utility\BcSiteConfig;
use BaserCore\Utility\BcUtil;
use BcBackup\Service\DbDumper\DbDumperInterface;
use BcBackup\Service\DbDumper\MysqlDbDumper;
use BcBackup\Service\DbDumper\PostgresDbDumper;
use BcBackup\Service\DbDumper\SqliteDbDumper;
use BcBackup\Service\DbDumper\StandardDbDumper;
use BcBackup\Utility\BcBackupArchiver;
use BcBackup\Utility\BcBackupCatalog;
use BcBackup\Utility\BcBackupLock;
use BcBackup\Utility\BcBackupUtil;
use Cake\Core\Configure;
use Cake\Log\LogTrait;

/**
 * BcBackupService
 *
 * バックアップ・リストアの統括サービス。
 * DB は毎回フル、files はフル／差分（チェーンID＋連番方式）で取得する。
 */
class BcBackupService implements BcBackupServiceInterface
{

    /**
     * Trait
     */
    use BcContainerTrait;
    use LogTrait;

    /**
     * 設定サービス
     * @var BcBackupConfigsServiceInterface
     */
    protected BcBackupConfigsServiceInterface $configsService;

    /**
     * constructor.
     *
     * @param BcBackupConfigsServiceInterface|null $configsService
     */
    public function __construct(?BcBackupConfigsServiceInterface $configsService = null)
    {
        $this->configsService = $configsService ?? new BcBackupConfigsService();
    }

    /**
     * バックアップを実行する
     *
     * @param string $type 'full' | 'diff'
     * @param callable|null $logger
     * @return array
     */
    public function backup(string $type = 'full', ?callable $logger = null): array
    {
        if (!in_array($type, ['full', 'diff'], true)) {
            throw new BcException(__d('baser_core', 'バックアップ種別が不正です。{0}', $type));
        }
        $config = $this->configsService->getConfig();
        $lock = new BcBackupLock();
        if (!$lock->acquire()) {
            throw new BcException(__d('baser_core', '他のバックアップ処理が実行中のため中止しました。'));
        }
        $timestamp = date('Ymd_His');
        $this->updateStatus(['state' => 'running', 'type' => $type, 'message' => __d('baser_core', 'バックアップを開始しました。'), 'started' => date('Y-m-d H:i:s'), 'finished' => null, 'name' => null, 'progress' => null]);

        $saveDir = rtrim((string)$config['save_path'], DS);
        $dbWorkDir = BcBackupUtil::getWorkDir() . 'db_' . $timestamp;
        $maintenanceChanged = false;
        $originalMaintenance = BcSiteConfig::get('maintenance');

        try {
            set_time_limit(0);
            $this->prepareSaveDir($saveDir);
            $catalog = new BcBackupCatalog($saveDir);
            $catalog->cleanTmpFiles();

            // 対象ディレクトリ（ROOT 相対）を組み立てる
            $targets = $this->parseTargets((string)$config['targets']);
            if (!$targets) {
                throw new BcException(__d('baser_core', 'バックアップ対象ディレクトリが設定されていません。'));
            }
            $strict = ($config['diff_mode'] === 'strict');

            // チェーンの決定。差分は最新チェーンに連なる。フルが無ければフルへ切替
            $chainId = $timestamp;
            $seq = 0;
            $previousManifest = null;
            if ($type === 'diff') {
                $latest = $this->getLatestBackup($catalog);
                if (!$latest) {
                    $this->notify($logger, __d('baser_core', 'フルバックアップが存在しないため、フルバックアップに切り替えます。'));
                    $type = 'full';
                } else {
                    $chainId = $latest['chain_id'];
                    $seq = (int)$latest['seq'] + 1;
                    $previousManifest = $catalog->readManifest($saveDir . DS . $latest['name']);
                    if (!$previousManifest) {
                        throw new BcException(__d('baser_core', '前回バックアップのマニフェストを読み込めませんでした。{0}', $latest['name']));
                    }
                }
            }
            $name = ($type === 'full')?
                sprintf('%s_full.zip', $timestamp) :
                sprintf('%s_diff_%s_%03d.zip', $timestamp, $chainId, $seq);

            // メンテナンスモードを ON（スキャン〜ZIP 完成まで整合を確保）
            if (!empty($config['use_maintenance_mode'])) {
                $this->notify($logger, __d('baser_core', 'メンテナンスモードを ON にします。'));
                $this->setMaintenanceMode(true);
                $maintenanceChanged = true;
            }

            // 対象ファイルのスキャン
            $this->notify($logger, __d('baser_core', '対象ファイルをスキャンしています...'));
            $archiver = new BcBackupArchiver();
            $snapshot = $archiver->scan($targets, [$saveDir], $strict);

            // アーカイブ対象と削除ファイルの決定
            if ($type === 'diff') {
                $diff = $archiver->diff($snapshot, $previousManifest['files'] ?? [], $strict);
                $archived = $diff['changed'];
                $deleted = $diff['deleted'];
                $this->notify($logger, __d('baser_core', '差分: 変更・追加 {0} 件 / 削除 {1} 件', count($archived), count($deleted)));
            } else {
                $archived = array_keys($snapshot);
                $deleted = [];
                $this->notify($logger, __d('baser_core', '対象ファイル {0} 件', count($archived)));
            }

            // ディスク空き容量チェック
            $this->checkDiskSpace($saveDir, $catalog, $type, $snapshot, $archived);

            // DB バックアップ（毎回フル）
            $this->notify($logger, __d('baser_core', 'DB バックアップを実行しています...'));
            $dbResult = $this->dumpDb($config, $dbWorkDir, $logger, function($done, $total) {
                $this->updateStatus([
                    'message' => __d('baser_core', 'DB バックアップを実行しています...（テーブル {0}/{1}）', $done, $total),
                    'progress' => ['done' => $done, 'total' => $total],
                ]);
            });

            // ZIP 生成（一時名で生成→rename）
            $this->notify($logger, __d('baser_core', 'アーカイブを作成しています...'));
            $manifest = [
                'format_version' => 1,
                'type' => $type,
                'chain_id' => $chainId,
                'seq' => $seq,
                'created' => date('Y-m-d H:i:s'),
                'versions' => [
                    'baser' => BcUtil::getVersion(),
                    'php' => PHP_VERSION,
                ],
                'db' => $dbResult,
                'targets' => $targets,
                'diff_mode' => $config['diff_mode'],
                'files' => $snapshot,
                'archived' => $archived,
                'deleted' => $deleted,
            ];
            $distPath = $saveDir . DS . $name;
            $archiver->create($distPath, $manifest, $dbWorkDir, $archived, function($done, $total) {
                $this->updateStatus([
                    'message' => __d('baser_core', 'アーカイブを作成しています...（ファイル {0}/{1}）', $done, $total),
                    'progress' => ['done' => $done, 'total' => $total],
                ]);
            });

            // メンテナンスモードを元に戻す（以降はサイト公開状態で問題ない）
            if ($maintenanceChanged) {
                $this->setMaintenanceMode(!empty($originalMaintenance));
                $maintenanceChanged = false;
                $this->notify($logger, __d('baser_core', 'メンテナンスモードを元に戻しました。'));
            }

            // カタログ更新
            $entry = [
                'name' => $name,
                'type' => $type,
                'chain_id' => $chainId,
                'seq' => $seq,
                'created' => $manifest['created'],
                'size' => filesize($distPath),
                'db_format' => $dbResult['format'],
            ];
            $catalog->add($entry);

            // ローテーション（チェーン単位）
            $rotated = $this->rotate($catalog, $saveDir, $config, $chainId);
            foreach($rotated as $removed) {
                $this->notify($logger, __d('baser_core', 'ローテーションにより削除: {0}', $removed));
            }

            $message = __d('baser_core', 'バックアップが完了しました。{0}（{1}）', $name, BcBackupUtil::humanSize((int)$entry['size']));
            $this->notify($logger, $message);
            $this->log($message, 'info');
            $this->updateStatus(['state' => 'success', 'type' => $type, 'message' => $message, 'finished' => date('Y-m-d H:i:s'), 'name' => $name]);
            return $entry;
        } catch (\Throwable $e) {
            $this->log(__d('baser_core', 'バックアップに失敗しました。{0}', $e->getMessage()), 'error');
            $this->updateStatus(['state' => 'error', 'type' => $type, 'message' => $e->getMessage(), 'finished' => date('Y-m-d H:i:s')]);
            throw $e;
        } finally {
            // 途中でクラッシュしてもメンテナンスモードを確実に元へ戻す
            if ($maintenanceChanged) {
                try {
                    $this->setMaintenanceMode(!empty($originalMaintenance));
                } catch (\Throwable $e) {
                    $this->log(__d('baser_core', 'メンテナンスモードの復帰に失敗しました。{0}', $e->getMessage()), 'error');
                }
            }
            $this->removeDir($dbWorkDir);
            $lock->release();
        }
    }

    /**
     * バックアップ一覧を新しい順で取得する
     *
     * @return array
     */
    public function getBackups(): array
    {
        $config = $this->configsService->getConfig();
        $catalog = new BcBackupCatalog(rtrim((string)$config['save_path'], DS));
        return $catalog->listBackups();
    }

    /**
     * バックアップファイルのフルパスを取得する（存在検証付き）
     *
     * @param string $name
     * @return string|null
     */
    public function getBackupPath(string $name): ?string
    {
        if (!BcBackupUtil::isValidBackupName($name)) return null;
        $config = $this->configsService->getConfig();
        $path = rtrim((string)$config['save_path'], DS) . DS . $name;
        return is_file($path)? $path : null;
    }

    /**
     * バックアップを削除する
     *
     * フルを指定した場合はチェーン全体、差分を指定した場合は
     * その差分以降（同チェーン内）を削除する（差分の孤児化防止）。
     *
     * @param string $name
     * @return array 削除したバックアップ名の配列
     */
    public function delete(string $name): array
    {
        if (!BcBackupUtil::isValidBackupName($name)) {
            throw new BcException(__d('baser_core', 'バックアップファイル名が不正です。'));
        }
        $config = $this->configsService->getConfig();
        $saveDir = rtrim((string)$config['save_path'], DS);
        $catalog = new BcBackupCatalog($saveDir);
        $target = $catalog->findByName($name);
        if (!$target) {
            throw new BcException(__d('baser_core', 'バックアップが見つかりません。{0}', $name));
        }
        $data = $catalog->read();
        $chain = $data['chains'][$target['chain_id']];
        if ($target['type'] === 'full') {
            $removes = $chain['backups'];
        } else {
            $removes = array_values(array_filter(
                $chain['backups'],
                fn($b) => $b['type'] === 'diff' && $b['seq'] >= $target['seq']
            ));
        }
        $removed = [];
        foreach($removes as $backup) {
            $path = $saveDir . DS . $backup['name'];
            if (!is_file($path) || unlink($path)) {
                $removed[] = $backup['name'];
            }
        }
        if ($target['type'] === 'full') {
            $catalog->removeChain($target['chain_id']);
        } else {
            $data['chains'][$target['chain_id']]['backups'] = array_values(array_filter(
                $chain['backups'],
                fn($b) => !in_array($b['name'], $removed, true)
            ));
            $catalog->write($data);
        }
        $this->log(__d('baser_core', 'バックアップを削除しました。{0}', implode(', ', $removed)), 'info');
        return $removed;
    }

    /**
     * バックアップからリストアする
     *
     * フル＋差分チェーンを連番順に適用する。DB は指定バックアップのものを
     * 使用する（DB は毎回フルのため）。ネイティブダンプの DB は自動リストア
     * 対象外とし、手動リストア手順を返す。
     *
     * @param string $name
     * @param callable|null $logger
     * @return array
     */
    public function restore(string $name, ?callable $logger = null): array
    {
        if (!BcBackupUtil::isValidBackupName($name)) {
            throw new BcException(__d('baser_core', 'バックアップファイル名が不正です。'));
        }
        $config = $this->configsService->getConfig();
        $saveDir = rtrim((string)$config['save_path'], DS);
        $catalog = new BcBackupCatalog($saveDir);
        $sequence = $catalog->resolveRestoreSequence($name);
        if (!$sequence) {
            throw new BcException(__d('baser_core', 'リストアに必要なバックアップチェーンが揃っていません。{0}', $name));
        }
        $lock = new BcBackupLock();
        if (!$lock->acquire()) {
            throw new BcException(__d('baser_core', '他のバックアップ処理が実行中のため中止しました。'));
        }

        $maintenanceChanged = false;
        $originalMaintenance = BcSiteConfig::get('maintenance');
        $dbWorkDir = BcBackupUtil::getWorkDir() . 'restore_' . date('Ymd_His');
        $result = ['db' => 'none', 'files' => 0, 'manual_help' => null];

        try {
            set_time_limit(0);
            $archiver = new BcBackupArchiver();

            // リストア中のサイトは不整合状態のため、メンテナンスモードで保護する
            if (!empty($config['use_maintenance_mode'])) {
                $this->notify($logger, __d('baser_core', 'メンテナンスモードを ON にします。'));
                $this->setMaintenanceMode(true);
                $maintenanceChanged = true;
            }

            // DB リストア（指定バックアップの DB を使用）
            $targetZip = $saveDir . DS . $name;
            $manifest = $catalog->readManifest($targetZip);
            if (!$manifest) {
                throw new BcException(__d('baser_core', 'マニフェストを読み込めませんでした。{0}', $name));
            }
            $dbFormat = $manifest['db']['format'] ?? null;
            if ($dbFormat === 'standard') {
                $this->notify($logger, __d('baser_core', 'DB をリストアしています...'));
                $archiver->extractPrefix($targetZip, 'db', $dbWorkDir);
                $this->restoreDbStandard($dbWorkDir, (string)($manifest['db']['encoding'] ?? 'UTF-8'));
                $result['db'] = 'restored';
                $this->notify($logger, __d('baser_core', 'DB のリストアが完了しました。'));
            } elseif ($dbFormat) {
                // ネイティブダンプは手動リストアのみサポート
                $archiver->extractPrefix($targetZip, 'db', $dbWorkDir);
                $dumpFile = $dbWorkDir . DS . ($manifest['db']['file'] ?? 'dump.sql');
                $extractedTo = $saveDir . DS . 'manual_restore_' . date('Ymd_His');
                if (!is_dir($extractedTo)) mkdir($extractedTo, 0777, true);
                $movedTo = $extractedTo . DS . basename($dumpFile);
                if (is_file($dumpFile)) rename($dumpFile, $movedTo);
                $result['db'] = 'manual';
                $result['manual_help'] = $this->buildManualRestoreHelp($dbFormat, $movedTo);
                $this->notify($logger, __d('baser_core', 'ネイティブダンプのため DB は自動リストアされません。手動リストア手順を確認してください。'));
            }

            // files リストア（フル→差分の連番順に上書き展開→削除適用）
            foreach($sequence as $backup) {
                $zipPath = $saveDir . DS . $backup['name'];
                if (!is_file($zipPath)) {
                    throw new BcException(__d('baser_core', 'バックアップファイルが見つかりません。{0}', $backup['name']));
                }
                $this->notify($logger, __d('baser_core', 'ファイルを展開しています... {0}', $backup['name']));
                $result['files'] += $archiver->extractPrefix($zipPath, 'files', ROOT);
                $backupManifest = $catalog->readManifest($zipPath);
                foreach($backupManifest['deleted'] ?? [] as $deletedPath) {
                    $absolute = BcBackupUtil::resolveSafePath(ROOT, $deletedPath);
                    if ($absolute && is_file($absolute)) unlink($absolute);
                }
            }

            BcUtil::clearAllCache();
            $message = __d('baser_core', 'リストアが完了しました。{0}', $name);
            $this->notify($logger, $message);
            $this->log($message, 'info');
            return $result;
        } catch (\Throwable $e) {
            $this->log(__d('baser_core', 'リストアに失敗しました。{0}', $e->getMessage()), 'error');
            throw $e;
        } finally {
            if ($maintenanceChanged) {
                try {
                    $this->setMaintenanceMode(!empty($originalMaintenance));
                    $this->notify($logger, __d('baser_core', 'メンテナンスモードを元に戻しました。'));
                } catch (\Throwable $e) {
                    $this->log(__d('baser_core', 'メンテナンスモードの復帰に失敗しました。{0}', $e->getMessage()), 'error');
                }
            }
            $this->removeDir($dbWorkDir);
            $lock->release();
        }
    }

    /**
     * 実行状態を取得する
     *
     * @return array
     */
    public function getStatus(): array
    {
        $statusFile = BcBackupUtil::getWorkDir() . 'status.json';
        $status = ['state' => 'idle', 'message' => '', 'type' => null, 'started' => null, 'finished' => null, 'name' => null];
        if (is_file($statusFile)) {
            $saved = json_decode((string)file_get_contents($statusFile), true);
            if (is_array($saved)) $status = array_merge($status, $saved);
        }
        // プロセスがクラッシュした場合に running のまま残らないよう、ロック実態を優先する
        // （バックグラウンド起動直後はロック取得前のため、開始から30秒の猶予を設ける）
        $lock = new BcBackupLock();
        if ($status['state'] === 'running' && !$lock->isLocked()) {
            $startedAt = $status['started']? (strtotime($status['started']) ?: 0) : 0;
            $grace = (int)Configure::read('BcBackup.startGraceSeconds', 30);
            if (!$startedAt || time() - $startedAt > $grace) {
                $status['state'] = 'error';
                $status['message'] = __d('baser_core', 'バックアップ処理が異常終了した可能性があります。ログを確認してください。');
            }
        }
        return $status;
    }

    /**
     * バックアップをバックグラウンド実行する（GUI 用）
     *
     * バックグラウンドで CLI コマンドを起動し、GUI はステータスファイルを
     * ポーリングして進捗を表示する。exec が使えない環境では GUI 実行不可
     * とし、CLI（cron）を案内する。
     *
     * @param string $type 'full' | 'diff'
     * @return void
     */
    public function startBackgroundBackup(string $type): void
    {
        if (!in_array($type, ['full', 'diff'], true)) {
            throw new BcException(__d('baser_core', 'バックアップ種別が不正です。{0}', $type));
        }
        if (!BcBackupUtil::canExec() || !function_exists('exec')) {
            throw new BcException(__d('baser_core', 'この環境ではコマンド実行が許可されていないため、画面からのバックアップは利用できません。CLI（cron）をご利用ください。'));
        }
        $lock = new BcBackupLock();
        if ($lock->isLocked()) {
            throw new BcException(__d('baser_core', '他のバックアップ処理が実行中です。'));
        }
        $cake = ROOT . DS . 'bin' . DS . 'cake.php';
        if (!is_file($cake)) {
            throw new BcException(__d('baser_core', 'CLI コマンドが見つかりません。{0}', $cake));
        }
        // 起動直後のポーリングが直前の結果を拾わないよう、先に running へ更新しておく
        $this->updateStatus(['state' => 'running', 'type' => $type, 'message' => __d('baser_core', 'バックアップを起動しています...'), 'started' => date('Y-m-d H:i:s'), 'finished' => null, 'name' => null, 'progress' => null]);
        $logFile = BcBackupUtil::getWorkDir() . 'gui_run.log';
        $command = sprintf(
            'nohup %s %s bc_backup run --%s >> %s 2>&1 &',
            escapeshellarg($this->getPhpCliBinary()),
            escapeshellarg($cake),
            $type,
            escapeshellarg($logFile)
        );
        exec($command);
    }

    /**
     * PHP CLI バイナリのパスを解決する
     *
     * Web 実行時の PHP_BINARY は php-fpm 等を指すため、CLI バイナリを優先して探す。
     *
     * @return string
     */
    protected function getPhpCliBinary(): string
    {
        if (PHP_SAPI === 'cli') return PHP_BINARY;
        foreach([PHP_BINDIR . DS . 'php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_executable($candidate)) return $candidate;
        }
        return 'php';
    }

    /**
     * DB バックアップを実行する
     *
     * ネイティブ方式が利用不可・失敗した場合は標準方式へ自動フォールバックする。
     *
     * @param array $config
     * @param string $dbWorkDir
     * @param callable|null $logger
     * @return array manifest の db セクション
     */
    protected function dumpDb(array $config, string $dbWorkDir, ?callable $logger = null, ?callable $onProgress = null): array
    {
        if ($config['db_method'] === 'native') {
            $dumper = $this->createNativeDumper($config);
            if ($dumper && $dumper->isAvailable()) {
                try {
                    return $dumper->dump($dbWorkDir, $onProgress);
                } catch (\Throwable $e) {
                    $this->log(__d('baser_core', 'ネイティブ方式の DB バックアップに失敗したため標準方式へフォールバックします。{0}', $e->getMessage()), 'warning');
                    $this->notify($logger, __d('baser_core', 'ネイティブ方式に失敗したため標準方式へフォールバックします。'));
                    $this->removeDir($dbWorkDir);
                }
            } else {
                $this->notify($logger, __d('baser_core', 'ネイティブ方式が利用できないため標準方式で実行します。'));
            }
        }
        return (new StandardDbDumper())->dump($dbWorkDir, $onProgress);
    }

    /**
     * 現在の DB ドライバに対応するネイティブダンパーを生成する
     *
     * @param array $config
     * @return DbDumperInterface|null
     */
    protected function createNativeDumper(array $config): ?DbDumperInterface
    {
        $driver = (string)BcUtil::getCurrentDbConfig()['driver'];
        return match (true) {
            str_contains($driver, 'Mysql') => new MysqlDbDumper(
                (string)$config['mysqldump_path'],
                !empty($config['use_single_transaction'])
            ),
            str_contains($driver, 'Postgres') => new PostgresDbDumper((string)$config['pg_dump_path']),
            str_contains($driver, 'Sqlite') => new SqliteDbDumper(),
            default => null,
        };
    }

    /**
     * 標準方式（スキーマ PHP＋CSV）の DB リストア
     *
     * トランザクション内で drop → create → CSV 投入を行い、
     * 最後にシーケンスを更新する（PostgreSQL 対応）。
     *
     * @param string $dir 展開済みディレクトリ
     * @param string $encoding
     * @return void
     */
    protected function restoreDbStandard(string $dir, string $encoding): void
    {
        /* @var \BaserCore\Service\BcDatabaseService $dbService */
        $dbService = $this->getService(BcDatabaseServiceInterface::class);
        $prefix = BcUtil::getCurrentDbConfig()['prefix'];
        $files = array_map('basename', glob(rtrim($dir, DS) . DS . '*') ?: []);
        $path = rtrim($dir, DS) . DS;

        $db = BcUtil::getCurrentDb();
        $db->begin();
        try {
            foreach($files as $file) {
                if (!preg_match('/\.php$/', $file)) continue;
                $dbService->loadSchema(['type' => 'drop', 'path' => $path, 'file' => $file, 'prefix' => $prefix]);
            }
            foreach($files as $file) {
                if (!preg_match('/\.php$/', $file)) continue;
                $dbService->loadSchema(['type' => 'create', 'path' => $path, 'file' => $file, 'prefix' => $prefix]);
            }
            foreach($files as $file) {
                if (!preg_match('/\.csv$/', $file)) continue;
                $dbService->loadCsv(['path' => $path . $file, 'encoding' => $encoding]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $dbService->updateSequence();
    }

    /**
     * ネイティブダンプの手動リストア手順を組み立てる
     *
     * @param string $format
     * @param string $dumpPath
     * @return string
     */
    protected function buildManualRestoreHelp(string $format, string $dumpPath): string
    {
        $config = BcUtil::getCurrentDbConfig();
        $database = (string)$config['database'];
        return match ($format) {
            'mysqldump' => __d('baser_core', "以下のコマンドで手動リストアしてください。\nmysql -u {0} -p -h {1} {2} < {3}",
                $config['username'], $config['host'], $database, $dumpPath),
            'pg_dump' => __d('baser_core', "以下のコマンドで手動リストアしてください。\npsql -U {0} -h {1} {2} < {3}",
                $config['username'], $config['host'], $database, $dumpPath),
            'sqlite' => __d('baser_core', "サイトを停止した上で、以下のファイルを {0} に上書きコピーしてください。\n{1}",
                $database, $dumpPath),
            default => __d('baser_core', 'ダンプファイル: {0}', $dumpPath),
        };
    }

    /**
     * 保存先ディレクトリを準備する
     *
     * webroot 配下が指定された場合は .htaccess による直アクセス遮断を必須とする
     * （バックアップには全個人情報が含まれるため）。
     *
     * @param string $saveDir
     * @return void
     */
    protected function prepareSaveDir(string $saveDir): void
    {
        if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
            throw new BcException(__d('baser_core', '保存先フォルダを作成できませんでした。{0}', $saveDir));
        }
        if (!is_writable($saveDir)) {
            throw new BcException(__d('baser_core', '保存先フォルダに書き込みできません。{0}', $saveDir));
        }
        if (str_starts_with(rtrim($saveDir, DS) . DS, WWW_ROOT)) {
            $htaccess = $saveDir . DS . '.htaccess';
            if (!is_file($htaccess)) {
                if (file_put_contents($htaccess, "Require all denied\n") === false) {
                    throw new BcException(__d('baser_core', '保存先の保護ファイル（.htaccess）を設置できませんでした。{0}', $htaccess));
                }
            }
        }
    }

    /**
     * 対象ディレクトリ設定（改行区切り）をパースする
     *
     * @param string $targets
     * @return array ROOT 相対パスの配列
     */
    protected function parseTargets(string $targets): array
    {
        $result = [];
        foreach(preg_split('/\R/', $targets) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // 絶対パス・トラバーサルは受け付けない（ROOT 相対のみ）
            $safe = BcBackupUtil::resolveSafePath(ROOT, str_replace(DS, '/', $line));
            if ($safe === null) {
                throw new BcException(__d('baser_core', 'バックアップ対象ディレクトリの指定が不正です。{0}', $line));
            }
            $result[] = str_replace(DS, '/', substr($safe, strlen(ROOT . DS)));
        }
        return array_values(array_unique($result));
    }

    /**
     * 最新のバックアップ（最新チェーンの最後尾）を取得する
     *
     * @param BcBackupCatalog $catalog
     * @return array|null
     */
    protected function getLatestBackup(BcBackupCatalog $catalog): ?array
    {
        $backups = $catalog->listBackups();
        if (!$backups) return null;
        // 最新チェーンを特定し、そのチェーン内の最大連番を返す
        $latestChain = null;
        foreach($backups as $backup) {
            if ($backup['type'] === 'full') {
                $latestChain = $backup['chain_id'];
                break;
            }
        }
        if ($latestChain === null) return null;
        $chainBackups = array_values(array_filter($backups, fn($b) => $b['chain_id'] === $latestChain));
        usort($chainBackups, fn($a, $b) => $b['seq'] <=> $a['seq']);
        return $chainBackups[0] ?? null;
    }

    /**
     * ディスク空き容量をチェックする
     *
     * 前回同種バックアップの実績サイズ、なければ対象ファイルサイズの合計を
     * 推定サイズとし、空き容量が下回る場合はエラーとする。
     *
     * @param string $saveDir
     * @param BcBackupCatalog $catalog
     * @param string $type
     * @param array $snapshot
     * @param array $archived
     * @return void
     */
    protected function checkDiskSpace(string $saveDir, BcBackupCatalog $catalog, string $type, array $snapshot, array $archived): void
    {
        $free = @disk_free_space($saveDir);
        if ($free === false) return;
        $estimate = 0;
        foreach($catalog->listBackups() as $backup) {
            if ($backup['type'] === $type) {
                $estimate = (int)$backup['size'];
                break;
            }
        }
        if (!$estimate) {
            foreach($archived as $backupPath) {
                $estimate += (int)($snapshot[$backupPath]['size'] ?? 0);
            }
        }
        if ($estimate && $free < $estimate) {
            throw new BcException(__d('baser_core',
                '保存先の空き容量が不足しています。空き: {0} / 推定必要量: {1}',
                BcBackupUtil::humanSize((float)$free), BcBackupUtil::humanSize($estimate)
            ));
        }
    }

    /**
     * チェーン単位のローテーションを行う
     *
     * 保持世代数（チェーン数）・保持日数の両方をサポートし、
     * 先に達した条件で削除する。実行中のチェーンは削除しない。
     *
     * @param BcBackupCatalog $catalog
     * @param string $saveDir
     * @param array $config
     * @param string $currentChainId
     * @return array 削除したバックアップ名の配列
     */
    protected function rotate(BcBackupCatalog $catalog, string $saveDir, array $config, string $currentChainId): array
    {
        $keepGenerations = (int)$config['keep_generations'];
        $keepDays = (int)$config['keep_days'];
        if ($keepGenerations <= 0 && $keepDays <= 0) return [];

        $data = $catalog->read();
        $chains = $data['chains'];
        krsort($chains);
        $chainIds = array_keys($chains);

        $removeChainIds = [];
        if ($keepGenerations > 0 && count($chainIds) > $keepGenerations) {
            $removeChainIds = array_slice($chainIds, $keepGenerations);
        }
        if ($keepDays > 0) {
            $threshold = time() - $keepDays * 86400;
            foreach($chains as $chainId => $chain) {
                // チェーン内の最新バックアップが保持日数を超えていたら削除対象
                $newest = 0;
                foreach($chain['backups'] as $backup) {
                    $newest = max($newest, strtotime($backup['created']) ?: 0);
                }
                if ($newest && $newest < $threshold) {
                    $removeChainIds[] = $chainId;
                }
            }
        }
        $removeChainIds = array_values(array_unique(array_diff($removeChainIds, [$currentChainId])));

        $removed = [];
        foreach($removeChainIds as $chainId) {
            foreach($chains[$chainId]['backups'] as $backup) {
                $path = $saveDir . DS . $backup['name'];
                if (!is_file($path) || unlink($path)) {
                    $removed[] = $backup['name'];
                }
            }
            $catalog->removeChain($chainId);
        }
        return $removed;
    }

    /**
     * メンテナンスモードを切り替える
     *
     * 管理画面・管理者ログイン中・debug モードでは効かない仕様のため、
     * 完全な書き込み停止ではない点に注意。
     *
     * @param bool $enable
     * @return void
     */
    protected function setMaintenanceMode(bool $enable): void
    {
        /* @var \BaserCore\Service\SiteConfigsService $siteConfigsService */
        $siteConfigsService = $this->getService(SiteConfigsServiceInterface::class);
        $siteConfigsService->setValue('maintenance', $enable? '1' : '0');
    }

    /**
     * ステータスファイルを更新する（GUI のポーリング用）
     *
     * @param array $status
     * @return void
     */
    protected function updateStatus(array $status): void
    {
        $statusFile = BcBackupUtil::getWorkDir() . 'status.json';
        $current = [];
        if (is_file($statusFile)) {
            $saved = json_decode((string)file_get_contents($statusFile), true);
            if (is_array($saved)) $current = $saved;
        }
        $tmp = $statusFile . '.tmp';
        file_put_contents($tmp, json_encode(array_merge($current, $status), JSON_UNESCAPED_UNICODE));
        rename($tmp, $statusFile);
    }

    /**
     * 進捗を通知する
     *
     * @param callable|null $logger
     * @param string $message
     * @return void
     */
    protected function notify(?callable $logger, string $message): void
    {
        if ($logger) $logger($message);
        // 工程が切り替わったら前工程の進捗表示をクリアする
        $this->updateStatus(['message' => $message, 'progress' => null]);
    }

    /**
     * ディレクトリを再帰削除する
     *
     * @param string $dir
     * @return void
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($iterator as $info) {
            /* @var \SplFileInfo $info */
            if ($info->isDir()) {
                @rmdir($info->getPathname());
            } else {
                @unlink($info->getPathname());
            }
        }
        @rmdir($dir);
    }

}
