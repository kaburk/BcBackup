<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Utility;

/**
 * BcBackupLock
 *
 * flock によるロックファイルで多重実行を防止する。
 * cron の重複起動・GUI と cron の同時実行・コア標準の手動バックアップ
 * （共有の TMP/schema/ を使う）との並走事故を防ぐ。
 */
class BcBackupLock
{

    /**
     * ロックファイルのハンドル
     * @var resource|null
     */
    protected $handle = null;

    /**
     * ロックファイルのパス
     * @var string
     */
    protected string $path;

    /**
     * constructor.
     */
    public function __construct()
    {
        $this->path = BcBackupUtil::getWorkDir() . 'bc_backup.lock';
    }

    /**
     * ロックを取得する（ノンブロッキング）
     *
     * @return bool 取得できなければ false
     */
    public function acquire(): bool
    {
        $handle = fopen($this->path, 'c');
        if (!$handle) return false;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);
        $this->handle = $handle;
        return true;
    }

    /**
     * ロック中かどうか（自プロセス以外がロックを保持しているか）
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        if ($this->handle) return true;
        if (!file_exists($this->path)) return false;
        $handle = fopen($this->path, 'c');
        if (!$handle) return true;
        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) flock($handle, LOCK_UN);
        fclose($handle);
        return !$acquired;
    }

    /**
     * ロックを解放する
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

}
