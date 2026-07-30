<?php
declare(strict_types=1);
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 */

namespace BcBackup\Model\Table;

use BaserCore\Model\Table\AppTable;
use Cake\Validation\Validator;

/**
 * バックアップ設定モデル（キーバリュー）
 */
class BcBackupConfigsTable extends AppTable
{

    /**
     * Initialize
     *
     * @param array $config テーブル設定
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('BaserCore.BcKeyValue');
    }

    /**
     * Validation KeyValue
     *
     * @param Validator $validator
     * @return Validator
     */
    public function validationKeyValue(Validator $validator): Validator
    {
        $validator
            ->scalar('save_path')
            ->notEmptyString('save_path', __d('baser_core', '保存先フォルダを入力してください。'));
        $validator
            ->scalar('db_method')
            ->inList('db_method', ['standard', 'native'], __d('baser_core', 'DBバックアップ方式に不正な値が利用されています。'));
        $validator
            ->scalar('diff_mode')
            ->inList('diff_mode', ['fast', 'strict'], __d('baser_core', '差分検知モードに不正な値が利用されています。'));
        $validator
            ->add('keep_generations', 'checkNumeric', [
                'rule' => ['range', -1, 1000],
                'message' => __d('baser_core', '保持世代数は 0〜999 の数値で入力してください。')
            ]);
        $validator
            ->add('keep_days', 'checkNumeric', [
                'rule' => ['range', -1, 10000],
                'message' => __d('baser_core', '保持日数は 0〜9999 の数値で入力してください。')
            ]);
        return $validator;
    }

}
