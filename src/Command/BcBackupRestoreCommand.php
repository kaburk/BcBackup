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
 * リストアコマンド
 *
 * bin/cake bc_backup restore <name>
 *
 * 指定バックアップの DB をリストアし、files はチェーン（フル＋差分）を
 * 連番順に適用する。ネイティブダンプ（mysqldump 等）の DB は手動リストア
 * 手順の表示のみ行う。
 */
class BcBackupRestoreCommand extends Command
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
        return 'bc_backup restore';
    }

    /**
     * buildOptionParser
     *
     * @param ConsoleOptionParser $parser
     * @return ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(__d('baser_core', 'バックアップからリストアします。'));
        $parser->addArgument('name', [
            'help' => __d('baser_core', 'バックアップファイル名（bc_backup list で確認）'),
            'required' => true
        ]);
        $parser->addOption('force', [
            'help' => __d('baser_core', '確認プロンプトをスキップする'),
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
        $name = (string)$args->getArgument('name');

        if (!$args->getOption('force')) {
            $io->warning(__d('baser_core', 'リストアを実行すると現在の DB とファイルが上書きされ、元に戻せません。'));
            $io->warning(__d('baser_core', '事前に現在の状態のバックアップを取ってから実行することを強く推奨します。'));
            $answer = $io->askChoice(__d('baser_core', '{0} からリストアを実行しますか？', $name), ['y', 'n'], 'n');
            if ($answer !== 'y') {
                $io->out(__d('baser_core', 'リストアを中止しました。'));
                return static::CODE_SUCCESS;
            }
        }

        /* @var \BcBackup\Service\BcBackupService $service */
        $service = $this->getService(BcBackupServiceInterface::class);
        try {
            $result = $service->restore($name, function($message) use ($io) {
                $io->out($message);
            });
            if ($result['db'] === 'manual' && $result['manual_help']) {
                $io->out('');
                $io->warning($result['manual_help']);
            }
            $io->out(__d('baser_core', 'リストア完了: {0}（ファイル {1} 件）', $name, $result['files']));
            return static::CODE_SUCCESS;
        } catch (\Throwable $e) {
            $io->err(__d('baser_core', 'リストアに失敗しました。{0}', $e->getMessage()));
            return static::CODE_ERROR;
        }
    }

}
