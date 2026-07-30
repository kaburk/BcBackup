<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Utility;

/**
 * BcBackupUtil
 *
 * 環境検出・パス検証などの共通処理
 */
class BcBackupUtil
{

    /**
     * プラグインの作業ディレクトリ（ロック・ステータス・一時ファイル置き場）を取得する
     *
     * @return string 末尾 DS 付きのパス
     */
    public static function getWorkDir(): string
    {
        $dir = TMP . 'bc_backup' . DS;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * 外部コマンドが実行可能な環境かどうか
     *
     * @return bool
     */
    public static function canExec(): bool
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /**
     * 外部コマンドを実行する
     *
     * 認証情報をコマンドライン引数に載せないよう、環境変数・設定ファイルは
     * $env / 呼び出し側で用意して渡すこと。
     *
     * @param array $command コマンドと引数の配列
     * @param array|null $env 追加の環境変数（null で継承）
     * @return array ['code' => int, 'stdout' => string, 'stderr' => string]
     */
    public static function exec(array $command, ?array $env = null): array
    {
        if (!self::canExec()) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open is not available.'];
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $envVars = null;
        if ($env !== null) {
            // 既存の環境変数に追記する
            $envVars = array_merge(getenv(), $env);
        }
        $proc = proc_open($command, $spec, $pipes, ROOT, $envVars);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'Failed to start process.'];
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * コマンドを自動検出してフルパスを返す
     *
     * MariaDB 環境では mysqldump が mariadb-dump にリネームされているため、
     * 候補を複数指定できる。見つからない場合は空文字を返す。
     *
     * @param array $candidates コマンド名の候補
     * @return string
     */
    public static function detectCommand(array $candidates): string
    {
        if (!self::canExec()) return '';
        foreach($candidates as $command) {
            $result = self::exec(['which', $command]);
            if ($result['code'] === 0 && trim($result['stdout'])) {
                return trim(explode("\n", trim($result['stdout']))[0]);
            }
        }
        return '';
    }

    /**
     * バックアップファイル名として正当かどうか
     *
     * ディレクトリトラバーサル対策。ダウンロード・削除・リストアで
     * 外部入力のファイル名を受ける箇所では必ずこの検証を通すこと。
     *
     * @param string $name
     * @return bool
     */
    public static function isValidBackupName(string $name): bool
    {
        return (bool)preg_match('/\A[0-9]{8}_[0-9]{6}_(full|diff_[0-9]{8}_[0-9]{6}_[0-9]{3})\.zip\z/', $name);
    }

    /**
     * ZIP エントリの展開先パスを検証して絶対パスを返す
     *
     * zip-slip 対策。ベースディレクトリの外を指すパスは null を返す。
     *
     * @param string $baseDir 展開先ベースディレクトリ
     * @param string $relativePath ZIP エントリの相対パス
     * @return string|null
     */
    public static function resolveSafePath(string $baseDir, string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        if (str_contains($relativePath, "\0")) return null;
        $parts = explode('/', $relativePath);
        $safe = [];
        foreach($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') return null;
            $safe[] = $part;
        }
        if (!$safe) return null;
        return rtrim($baseDir, DS) . DS . implode(DS, $safe);
    }

    /**
     * バイト数を人間が読みやすい表記に変換する
     *
     * @param int|float $bytes
     * @return string
     */
    public static function humanSize(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, ($i > 1)? 2 : 0) . ' ' . $units[$i];
    }

}
