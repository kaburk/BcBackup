<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Command;

use BaserCore\Utility\BcContainerTrait;
use BcBackup\Service\BcBackupServiceInterface;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * バックアップ実行コマンド
 *
 * bin/cake bc_backup run --full   # フルバックアップ
 * bin/cake bc_backup run --diff   # 差分バックアップ（files のみ差分、DB はフル）
 *
 * cron 登録例:
 *   0 3 * * 1-6 cd /path/to/app && bin/cake bc_backup run --diff >> /var/log/bc_backup.log 2>&1
 *   0 2 * * 0   cd /path/to/app && bin/cake bc_backup run --full >> /var/log/bc_backup.log 2>&1
 */
class BcBackupRunCommand extends Command
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
        return 'bc_backup run';
    }

    /**
     * buildOptionParser
     *
     * @param ConsoleOptionParser $parser
     * @return ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(__d('baser_core', 'バックアップを実行します。'));
        $parser->addOption('full', [
            'help' => __d('baser_core', 'フルバックアップ（DB＋対象ディレクトリ全体）'),
            'boolean' => true,
            'default' => false
        ]);
        $parser->addOption('diff', [
            'help' => __d('baser_core', '差分バックアップ（files は前回からの差分、DB はフル）'),
            'boolean' => true,
            'default' => false
        ]);
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
        if ($args->getOption('full') && $args->getOption('diff')) {
            $io->err(__d('baser_core', '--full と --diff は同時に指定できません。'));
            return static::CODE_ERROR;
        }
        $type = $args->getOption('diff')? 'diff' : 'full';

        /* @var \BcBackup\Service\BcBackupService $service */
        $service = $this->getService(BcBackupServiceInterface::class);
        try {
            $entry = $service->backup($type, function($message) use ($io) {
                $io->out($message);
            });
            $io->out(__d('baser_core', 'バックアップ完了: {0}', $entry['name']));
            return static::CODE_SUCCESS;
        } catch (\Throwable $e) {
            // cron の MAILTO によるメール通知が機能するよう、stderr ＋ 非0終了とする
            $io->err(__d('baser_core', 'バックアップに失敗しました。{0}', $e->getMessage()));
            return static::CODE_ERROR;
        }
    }

}
