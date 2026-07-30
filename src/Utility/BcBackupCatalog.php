<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Utility;

use ZipArchive;

/**
 * BcBackupCatalog
 *
 * 保存先ディレクトリの管理台帳（catalog.json）を扱う。
 *
 * 世代管理の台帳は DB に持たせず、保存先内の catalog.json と
 * 各 ZIP 内の manifest.json を正とする（DBリストアで管理情報が
 * 巻き戻ると実ファイルと不整合になるため）。
 */
class BcBackupCatalog
{

    /**
     * 保存先ディレクトリ
     * @var string
     */
    protected string $saveDir;

    /**
     * constructor.
     *
     * @param string $saveDir 保存先ディレクトリ
     */
    public function __construct(string $saveDir)
    {
        $this->saveDir = rtrim($saveDir, DS);
    }

    /**
     * catalog.json のパスを取得する
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->saveDir . DS . 'catalog.json';
    }

    /**
     * カタログを読み込む
     *
     * catalog.json が存在しない・壊れている場合は ZIP のスキャンから再構築する。
     *
     * @return array ['version' => 1, 'chains' => [chainId => ['id' =>, 'backups' => [...]]]]
     */
    public function read(): array
    {
        $path = $this->getPath();
        if (is_file($path)) {
            $catalog = json_decode((string)file_get_contents($path), true);
            if (is_array($catalog) && isset($catalog['chains'])) {
                return $catalog;
            }
        }
        return $this->rebuild();
    }

    /**
     * カタログを原子的に書き込む（テンポラリ書き込み→rename）
     *
     * @param array $catalog
     * @return bool
     */
    public function write(array $catalog): bool
    {
        $path = $this->getPath();
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return false;
        }
        return rename($tmp, $path);
    }

    /**
     * バックアップをカタログに追加する
     *
     * @param array $entry manifest から抜粋したカタログエントリ
     * @return bool
     */
    public function add(array $entry): bool
    {
        $catalog = $this->read();
        $chainId = $entry['chain_id'];
        if (!isset($catalog['chains'][$chainId])) {
            $catalog['chains'][$chainId] = ['id' => $chainId, 'backups' => []];
        }
        $catalog['chains'][$chainId]['backups'][] = $entry;
        return $this->write($catalog);
    }

    /**
     * チェーンをカタログから削除する
     *
     * @param string $chainId
     * @return bool
     */
    public function removeChain(string $chainId): bool
    {
        $catalog = $this->read();
        unset($catalog['chains'][$chainId]);
        return $this->write($catalog);
    }

    /**
     * 全バックアップを新しい順のフラット配列で取得する
     *
     * @return array
     */
    public function listBackups(): array
    {
        $catalog = $this->read();
        $backups = [];
        foreach($catalog['chains'] as $chain) {
            foreach($chain['backups'] as $backup) {
                $backups[] = $backup;
            }
        }
        usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $backups;
    }

    /**
     * 名前からバックアップエントリを取得する
     *
     * @param string $name
     * @return array|null
     */
    public function findByName(string $name): ?array
    {
        foreach($this->listBackups() as $backup) {
            if ($backup['name'] === $name) return $backup;
        }
        return null;
    }

    /**
     * リストアに必要なバックアップの適用順リストを取得する
     *
     * 指定がフルならそれのみ、差分ならチェーンのフル＋連番順の差分
     * （指定連番まで）を返す。
     *
     * @param string $name
     * @return array|null 適用順の配列。チェーンが揃わない場合は null
     */
    public function resolveRestoreSequence(string $name): ?array
    {
        $target = $this->findByName($name);
        if (!$target) return null;
        $catalog = $this->read();
        $chain = $catalog['chains'][$target['chain_id']] ?? null;
        if (!$chain) return null;
        $sequence = [];
        $full = null;
        foreach($chain['backups'] as $backup) {
            if ($backup['type'] === 'full') $full = $backup;
        }
        if (!$full) return null;
        $sequence[] = $full;
        if ($target['type'] === 'diff') {
            $diffs = array_filter($chain['backups'], fn($b) => $b['type'] === 'diff' && $b['seq'] <= $target['seq']);
            usort($diffs, fn($a, $b) => $a['seq'] <=> $b['seq']);
            // 連番が抜けているチェーンは復元不能とみなす
            $expected = 1;
            foreach($diffs as $diff) {
                if ($diff['seq'] !== $expected) return null;
                $sequence[] = $diff;
                $expected++;
            }
        }
        return $sequence;
    }

    /**
     * 保存先の ZIP をスキャンしてカタログを再構築する
     *
     * @return array
     */
    public function rebuild(): array
    {
        $catalog = ['version' => 1, 'chains' => []];
        if (!is_dir($this->saveDir)) return $catalog;
        foreach(glob($this->saveDir . DS . '*.zip') ?: [] as $file) {
            $name = basename($file);
            if (!BcBackupUtil::isValidBackupName($name)) continue;
            $manifest = $this->readManifest($file);
            if (!$manifest) continue;
            $entry = [
                'name' => $name,
                'type' => $manifest['type'],
                'chain_id' => $manifest['chain_id'],
                'seq' => $manifest['seq'],
                'created' => $manifest['created'],
                'size' => filesize($file),
                'db_format' => $manifest['db']['format'] ?? null,
            ];
            $chainId = $entry['chain_id'];
            if (!isset($catalog['chains'][$chainId])) {
                $catalog['chains'][$chainId] = ['id' => $chainId, 'backups' => []];
            }
            $catalog['chains'][$chainId]['backups'][] = $entry;
        }
        foreach($catalog['chains'] as &$chain) {
            usort($chain['backups'], fn($a, $b) => $a['seq'] <=> $b['seq']);
        }
        unset($chain);
        $this->write($catalog);
        return $catalog;
    }

    /**
     * ZIP 内の manifest.json を読み込む
     *
     * @param string $zipPath
     * @return array|null
     */
    public function readManifest(string $zipPath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return null;
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        if ($json === false) return null;
        $manifest = json_decode($json, true);
        return is_array($manifest)? $manifest : null;
    }

    /**
     * 起動時の残骸（.tmp）を掃除する
     *
     * @return void
     */
    public function cleanTmpFiles(): void
    {
        if (!is_dir($this->saveDir)) return;
        foreach(glob($this->saveDir . DS . '*.tmp') ?: [] as $file) {
            @unlink($file);
        }
    }

}
