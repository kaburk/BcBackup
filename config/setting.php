<?php
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 *
 * デフォルト設定。環境ごとの上書きは config/setting_customize.php で行う
 * （setting_customize.php.default をリネームして使用。Git 管理対象外）。
 */

use Cake\Utility\Hash;

$config = [
    'BcApp' => [
        /**
         * システムナビ
         */
        'adminNavigation' => [
            'Plugins' => [
                'menus' => [
                    'BcBackup' => [
                        'title' => __d('baser_core', 'バックアップ'),
                        'url' => [
                            'prefix' => 'Admin',
                            'plugin' => 'BcBackup',
                            'controller' => 'BcBackup',
                            'action' => 'index'
                        ]
                    ]
                ]
            ],
        ]
    ],
    'BcBackup' => [
        /**
         * 進捗通知の間隔（アーカイブ作成時、この件数ごとにステータスを更新する）
         */
        'progressInterval' => 25,
        /**
         * バックグラウンド実行のロック取得を待つ猶予秒数
         *
         * この時間内にロックが取得されない場合でも異常終了と判定しない。
         */
        'startGraceSeconds' => 30,
        /**
         * 管理画面のステータスポーリング間隔（ミリ秒）
         */
        'pollingIntervalMs' => 3000,
    ],
];

// setting_customize.php が存在すれば深いマージで上書き
if (file_exists(__DIR__ . DS . 'setting_customize.php')) {
    include __DIR__ . DS . 'setting_customize.php';
    if (!empty($customize_config) && is_array($customize_config)) {
        $config = Hash::merge($config, $customize_config);
        // Hash::merge はインデックス配列（リスト）を追記してしまうため、
        // リスト型の設定はカスタマイズ側の値で丸ごと置換する
        foreach($customize_config as $sectionKey => $section) {
            if (!is_array($section)) continue;
            foreach($section as $key => $value) {
                if (is_array($value) && array_is_list($value)) {
                    $config[$sectionKey][$key] = $value;
                }
            }
        }
    }
}

return $config;
