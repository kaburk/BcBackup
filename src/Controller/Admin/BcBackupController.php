<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Controller\Admin;

use BaserCore\Controller\Admin\BcAdminAppController;
use BaserCore\Error\BcException;
use BcBackup\Service\BcBackupConfigsServiceInterface;
use BcBackup\Service\BcBackupServiceInterface;
use BcBackup\Utility\BcBackupUtil;
use Cake\Http\Exception\NotFoundException;

/**
 * バックアップ管理コントローラー
 */
class BcBackupController extends BcAdminAppController
{

    /**
     * [ADMIN] バックアップ設定・一覧
     *
     * POST 時は設定を保存する。
     *
     * @param BcBackupConfigsServiceInterface $configsService
     * @param BcBackupServiceInterface $service
     * @return \Cake\Http\Response|void
     */
    public function index(
        BcBackupConfigsServiceInterface $configsService,
        BcBackupServiceInterface $service
    )
    {
        // バックグラウンド実行完了後のリロード時（?completed=1）は結果をフラッシュ表示する
        if ($this->getRequest()->getQuery('completed')) {
            $status = $service->getStatus();
            if ($status['state'] === 'success') {
                $this->BcMessage->setSuccess($status['message']);
            } elseif ($status['state'] === 'error') {
                $this->BcMessage->setError($status['message']);
            }
            return $this->redirect(['action' => 'index']);
        }

        $backupConfig = $configsService->get();
        if ($this->getRequest()->is(['post', 'put'])) {
            $backupConfig = $configsService->update($this->getRequest()->getData());
            if ($backupConfig && !$backupConfig->getErrors()) {
                $this->BcMessage->setSuccess(__d('baser_core', 'バックアップ設定を保存しました。'));
                return $this->redirect(['action' => 'index']);
            }
            $this->BcMessage->setError(__d('baser_core', '入力エラーです。内容を修正してください。'));
            if (!$backupConfig) $backupConfig = $configsService->get();
        }

        $backups = [];
        $listError = null;
        try {
            $backups = $service->getBackups();
        } catch (\Throwable $e) {
            $listError = $e->getMessage();
        }

        $this->set([
            'backupConfig' => $backupConfig,
            'backups' => $backups,
            'listError' => $listError,
            'status' => $service->getStatus(),
            'canExec' => BcBackupUtil::canExec(),
            'rootPath' => ROOT,
        ]);
    }

    /**
     * [ADMIN] バックアップ実行（バックグラウンド起動）
     *
     * @param BcBackupServiceInterface $service
     * @return \Cake\Http\Response
     */
    public function execute(BcBackupServiceInterface $service)
    {
        $this->getRequest()->allowMethod(['post']);
        $type = ($this->getRequest()->getData('type') === 'diff')? 'diff' : 'full';
        try {
            $service->startBackgroundBackup($type);
            $this->BcMessage->setSuccess(__d('baser_core', 'バックアップを開始しました。完了までしばらくお待ちください。'));
        } catch (BcException $e) {
            $this->BcMessage->setError($e->getMessage());
        }
        return $this->redirect(['action' => 'index']);
    }

    /**
     * [ADMIN] 実行状態の取得（ポーリング用）
     *
     * @param BcBackupServiceInterface $service
     * @return \Cake\Http\Response
     */
    public function status(BcBackupServiceInterface $service)
    {
        $this->getRequest()->allowMethod(['get']);
        return $this->getResponse()
            ->withType('application/json')
            ->withStringBody((string)json_encode($service->getStatus(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * [ADMIN] バックアップのダウンロード
     *
     * @param BcBackupServiceInterface $service
     * @param string $name
     * @return \Cake\Http\Response
     */
    public function download(BcBackupServiceInterface $service, string $name)
    {
        $path = $service->getBackupPath($name);
        if (!$path) {
            throw new NotFoundException(__d('baser_core', 'バックアップが見つかりません。'));
        }
        return $this->getResponse()->withFile($path, ['download' => true, 'name' => $name]);
    }

    /**
     * [ADMIN] バックアップの削除
     *
     * フルを指定した場合はチェーン全体、差分を指定した場合は
     * その差分以降（同チェーン内）を削除する。
     *
     * @param BcBackupServiceInterface $service
     * @param string $name
     * @return \Cake\Http\Response
     */
    public function delete(BcBackupServiceInterface $service, string $name)
    {
        $this->getRequest()->allowMethod(['post', 'delete']);
        try {
            $removed = $service->delete($name);
            $this->BcMessage->setSuccess(__d('baser_core', 'バックアップを削除しました。{0}', implode(', ', $removed)));
        } catch (\Throwable $e) {
            $this->BcMessage->setError(__d('baser_core', 'バックアップの削除に失敗しました。{0}', $e->getMessage()));
        }
        return $this->redirect(['action' => 'index']);
    }

}
