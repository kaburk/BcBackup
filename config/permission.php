<?php
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 *
 * アクセスルール初期値
 */

return [
    'permission' => [

        /**
         * 管理画面
         */
        'BcBackupAdmin' => [
            'title' => __d('baser_core', 'バックアップ管理'),
            'plugin' => 'BcBackup',
            'type' => 'Admin',
            'items' => [
                'Index' => ['title' => __d('baser_core', '設定・一覧'), 'url' => '/baser/admin/bc-backup/bc_backup/index', 'method' => 'GET', 'auth' => false],
                'Save' => ['title' => __d('baser_core', '設定保存'), 'url' => '/baser/admin/bc-backup/bc_backup/index', 'method' => 'POST', 'auth' => false],
                'Execute' => ['title' => __d('baser_core', 'バックアップ実行'), 'url' => '/baser/admin/bc-backup/bc_backup/execute', 'method' => 'POST', 'auth' => false],
                'Status' => ['title' => __d('baser_core', '実行状態取得'), 'url' => '/baser/admin/bc-backup/bc_backup/status', 'method' => 'GET', 'auth' => false],
                'Download' => ['title' => __d('baser_core', 'ダウンロード'), 'url' => '/baser/admin/bc-backup/bc_backup/download/*', 'method' => 'GET', 'auth' => false],
                'Delete' => ['title' => __d('baser_core', '削除'), 'url' => '/baser/admin/bc-backup/bc_backup/delete/*', 'method' => 'POST', 'auth' => false],
            ]
        ],
    ]
];
