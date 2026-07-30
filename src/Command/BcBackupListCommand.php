<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Command;

use BaserCore\Utility\BcContainerTrait;
use BcBackup\Service\BcBackupServiceInterface;
use BcBackup\Utility\BcBackupUtil;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * バックアップ一覧コマンド
 *
 * bin/cake bc_backup list
 */
class BcBackupListCommand extends Command
{

    /**
     * Trait
     */
    use BcContainerTrait;

    /**
     * デフォルトコマンド名
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'bc_backup list';
    }

    /**
     * buildOptionParser
     *
     * @param ConsoleOptionParser $parser
     * @return ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(__d('baser_core', 'バックアップ一覧を表示します。'));
        return $parser;
    }

    /**
     * execute
     *
     * @param Arguments $args
     * @param ConsoleIo $io
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        /* @var \BcBackup\Service\BcBackupService $service */
        $service = $this->getService(BcBackupServiceInterface::class);
        try {
            $backups = $service->getBackups();
        } catch (\Throwable $e) {
            $io->err(__d('baser_core', 'バックアップ一覧の取得に失敗しました。{0}', $e->getMessage()));
            return static::CODE_ERROR;
        }
        if (!$backups) {
            $io->out(__d('baser_core', 'バックアップはありません。'));
            return static::CODE_SUCCESS;
        }
        $rows = [[
            __d('baser_core', 'ファイル名'),
            __d('baser_core', '種別'),
            __d('baser_core', '取得日時'),
            __d('baser_core', 'サイズ'),
            __d('baser_core', 'DB方式'),
        ]];
        foreach($backups as $backup) {
            $rows[] = [
                $backup['name'],
                ($backup['type'] === 'full')? __d('baser_core', 'フル') : __d('baser_core', '差分'),
                $backup['created'],
                BcBackupUtil::humanSize((int)$backup['size']),
                (string)$backup['db_format'],
            ];
        }
        $io->helper('Table')->output($rows);
        return static::CODE_SUCCESS;
    }

}
