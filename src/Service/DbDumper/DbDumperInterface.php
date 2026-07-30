<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Service\DbDumper;

/**
 * DbDumperInterface
 */
interface DbDumperInterface
{

    /**
     * この方式が現在の環境で利用可能かどうか
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * DB バックアップを作業ディレクトリへ出力する
     *
     * @param string $workDir 出力先ディレクトリ（末尾 DS なし）
     * @param callable|null $onProgress 進捗コールバック（done, total を受け取る）
     * @return array manifest の db セクション ['format' => string, ...]
     * @throws \BaserCore\Error\BcException 失敗時
     */
    public function dump(string $workDir, ?callable $onProgress = null): array;

}
