<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service;

/**
 * BcBackupServiceInterface
 */
interface BcBackupServiceInterface
{

    /**
     * バックアップを実行する
     *
     * @param string $type 'full' | 'diff'
     * @param callable|null $logger 進捗メッセージを受け取るコールバック
     * @return array 作成したバックアップのカタログエントリ
     */
    public function backup(string $type = 'full', ?callable $logger = null): array;

    /**
     * バックアップ一覧を新しい順で取得する
     *
     * @return array
     */
    public function getBackups(): array;

    /**
     * バックアップファイルのフルパスを取得する（存在検証付き）
     *
     * @param string $name
     * @return string|null
     */
    public function getBackupPath(string $name): ?string;

    /**
     * バックアップを削除する
     *
     * フルを指定した場合はチェーン全体、差分を指定した場合は
     * その差分以降（同チェーン内）を削除する（差分の孤児化防止）。
     *
     * @param string $name
     * @return array 削除したバックアップ名の配列
     */
    public function delete(string $name): array;

    /**
     * バックアップからリストアする
     *
     * @param string $name
     * @param callable|null $logger
     * @return array 実行結果 ['db' => 'restored'|'manual'|'none', 'files' => int, 'manual_help' => ?string]
     */
    public function restore(string $name, ?callable $logger = null): array;

    /**
     * 実行状態を取得する
     *
     * @return array ['state' => 'idle'|'running'|'success'|'error', ...]
     */
    public function getStatus(): array;

    /**
     * バックアップをバックグラウンド実行する（GUI 用）
     *
     * @param string $type 'full' | 'diff'
     * @return void
     * @throws \BaserCore\Error\BcException exec が利用できない環境
     */
    public function startBackgroundBackup(string $type): void;

}
