<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Utility;

use BaserCore\Error\BcException;
use Cake\Core\Configure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;

/**
 * BcBackupArchiver
 *
 * バックアップ ZIP の生成を担う。
 * コアの BcZip::create() は除外パターン・エラー処理を持たないため、
 * ZipArchive を直接利用して独自実装する。
 *
 * - 一時名（.tmp）で生成し、完成後に rename する（原子的書き込み）
 * - 保存先ディレクトリ自身を対象から除外する（自己包含防止）
 *
 * ZIP 内レイアウト:
 *   manifest.json          バックアップのメタ情報
 *   db/                    DBバックアップ（標準: スキーマPHP＋CSV / ネイティブ: dump.sql 等）
 *   files/{ROOT相対パス}   対象ディレクトリのファイル
 */
class BcBackupArchiver
{

    /**
     * 対象ディレクトリをスキャンしてファイル一覧（スナップショット）を作成する
     *
     * @param array $targets ROOT からの相対パスの配列
     * @param array $excludeDirs 除外する絶対パスの配列（保存先など）
     * @param bool $withHash ハッシュ（sha256）を含めるか（厳格モード）
     * @return array [相対パス => ['mtime' => int, 'size' => int, 'hash' => ?string]]
     */
    public function scan(array $targets, array $excludeDirs = [], bool $withHash = false): array
    {
        $snapshot = [];
        $excludeDirs = array_map(fn($dir) => rtrim($dir, DS) . DS, $excludeDirs);
        foreach($targets as $target) {
            $base = ROOT . DS . str_replace('/', DS, trim($target, '/'));
            if (!is_dir($base)) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach($iterator as $info) {
                /* @var \SplFileInfo $info */
                // シンボリックリンクはループの危険があるため対象外とする
                if ($info->isLink() || !$info->isFile()) continue;
                $path = $info->getPathname();
                foreach($excludeDirs as $exclude) {
                    if (str_starts_with($path . DS, $exclude) || str_starts_with($path, $exclude)) continue 2;
                }
                $relative = str_replace(DS, '/', substr($path, strlen(ROOT . DS)));
                $snapshot[$relative] = [
                    'mtime' => $info->getMTime(),
                    'size' => $info->getSize(),
                    'hash' => $withHash? hash_file('sha256', $path) : null,
                ];
            }
        }
        ksort($snapshot);
        return $snapshot;
    }

    /**
     * 前回スナップショットとの差分（変更・追加／削除）を抽出する
     *
     * @param array $current 今回のスナップショット
     * @param array $previous 前回のスナップショット
     * @param bool $strict 厳格モード（ハッシュ比較）
     * @return array ['changed' => string[], 'deleted' => string[]]
     */
    public function diff(array $current, array $previous, bool $strict = false): array
    {
        $changed = [];
        foreach($current as $path => $meta) {
            if (!isset($previous[$path])) {
                $changed[] = $path;
                continue;
            }
            $prev = $previous[$path];
            if ($strict && !empty($meta['hash']) && !empty($prev['hash'])) {
                if ($meta['hash'] !== $prev['hash']) $changed[] = $path;
            } elseif ($meta['mtime'] !== $prev['mtime'] || $meta['size'] !== $prev['size']) {
                $changed[] = $path;
            }
        }
        $deleted = array_values(array_diff(array_keys($previous), array_keys($current)));
        return ['changed' => $changed, 'deleted' => $deleted];
    }

    /**
     * バックアップ ZIP を生成する
     *
     * @param string $distPath 出力先の ZIP パス
     * @param array $manifest manifest.json の内容
     * @param string|null $dbDir 梱包する DB バックアップのディレクトリ（db/ 配下へ格納）
     * @param array $files 梱包するファイルの相対パス（ROOT 起点）の配列
     * @param callable|null $onProgress 進捗コールバック（done, total を受け取る）
     * @return void
     * @throws BcException
     */
    public function create(string $distPath, array $manifest, ?string $dbDir, array $files, ?callable $onProgress = null): void
    {
        $tmpPath = $distPath . '.tmp';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BcException(__d('baser_core', 'バックアップファイルを作成できませんでした。{0}', $tmpPath));
        }
        try {
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($dbDir && is_dir($dbDir)) {
                $this->addDirectory($zip, $dbDir, 'db');
            }
            $total = count($files);
            $done = 0;
            $interval = max(1, (int)Configure::read('BcBackup.progressInterval', 25));
            foreach($files as $relative) {
                $absolute = ROOT . DS . str_replace('/', DS, $relative);
                if (!is_file($absolute)) continue;
                if (!$zip->addFile($absolute, 'files/' . $relative)) {
                    throw new BcException(__d('baser_core', 'ファイルを ZIP に追加できませんでした。{0}', $relative));
                }
                $done++;
                // ファイル数が多い場合の書き込み負荷を抑えるため間引いて通知する
                if ($onProgress && ($done % $interval === 0 || $done === $total)) {
                    $onProgress($done, $total);
                }
            }
            if (!$zip->close()) {
                throw new BcException(__d('baser_core', 'バックアップファイルの書き込みに失敗しました。{0}', $tmpPath));
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($tmpPath);
            throw $e;
        }
        if (!rename($tmpPath, $distPath)) {
            @unlink($tmpPath);
            throw new BcException(__d('baser_core', 'バックアップファイルの配置に失敗しました。{0}', $distPath));
        }
    }

    /**
     * ディレクトリを再帰的に ZIP へ追加する
     *
     * @param ZipArchive $zip
     * @param string $sourceDir 追加元ディレクトリ
     * @param string $prefix ZIP 内の格納先プレフィックス
     * @return void
     */
    protected function addDirectory(ZipArchive $zip, string $sourceDir, string $prefix): void
    {
        $sourceDir = rtrim($sourceDir, DS);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach($iterator as $info) {
            /* @var \SplFileInfo $info */
            if (!$info->isFile()) continue;
            $relative = str_replace(DS, '/', substr($info->getPathname(), strlen($sourceDir . DS)));
            $zip->addFile($info->getPathname(), $prefix . '/' . $relative);
        }
    }

    /**
     * ZIP から指定プレフィックス配下のエントリを展開する
     *
     * コアの BcZip::extract() はアーカイブ全体の展開＋トップフォルダ前提のため、
     * プレフィックス単位の選択展開を独自実装する（zip-slip はパス検証で対策）。
     *
     * @param string $zipPath ZIP のパス
     * @param string $prefix ZIP 内のプレフィックス（'db' または 'files'）
     * @param string $destDir 展開先ディレクトリ
     * @param bool $stripPrefix プレフィックスを取り除いて展開するか
     * @return int 展開したファイル数
     * @throws BcException
     */
    public function extractPrefix(string $zipPath, string $prefix, string $destDir, bool $stripPrefix = true): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BcException(__d('baser_core', 'バックアップファイルを開けませんでした。{0}', $zipPath));
        }
        $count = 0;
        try {
            for($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if (!str_starts_with($entry, $prefix . '/')) continue;
                if (str_ends_with($entry, '/')) continue;
                $relative = $stripPrefix? substr($entry, strlen($prefix) + 1) : $entry;
                $destPath = BcBackupUtil::resolveSafePath($destDir, $relative);
                if ($destPath === null) {
                    throw new BcException(__d('baser_core', '不正なパスを含む ZIP のため展開を中止しました。{0}', $entry));
                }
                $dir = dirname($destPath);
                if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
                    throw new BcException(__d('baser_core', '展開先ディレクトリを作成できませんでした。{0}', $dir));
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new BcException(__d('baser_core', 'ZIP エントリを読み込めませんでした。{0}', $entry));
                }
                $out = fopen($destPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new BcException(__d('baser_core', 'ファイルを書き込めませんでした。{0}', $destPath));
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                // 更新日時を ZIP エントリの値へ戻す（リストア後の差分検知が全件変更扱いになるのを防ぐ）
                $stat = $zip->statIndex($i);
                if ($stat && !empty($stat['mtime'])) {
                    @touch($destPath, $stat['mtime']);
                }
                $count++;
            }
        } finally {
            $zip->close();
        }
        return $count;
    }

}
