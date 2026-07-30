<?php
/**
 * BcBackup : baserCMS 5 自動バックアッププラグイン
 *
 * [ADMIN] バックアップ設定・一覧
 *
 * @var \BaserCore\View\BcAdminAppView $this
 * @var \Cake\Datasource\EntityInterface $backupConfig
 * @var array $backups
 * @var string|null $listError
 * @var array $status
 * @var bool $canExec
 * @var string $rootPath
 */

use BcBackup\Utility\BcBackupUtil;

$this->BcAdmin->setTitle(__d('baser_core', 'バックアップ'));
?>

<!-- 実行状態 -->
<div class="section bca-section" id="BcBackupStatusSection" <?php if ($status['state'] !== 'running') echo 'style="display:none"' ?>>
  <div class="bca-panel-box">
    <h2><?php echo __d('baser_core', '実行状態') ?></h2>
    <p id="BcBackupStatusMessage"><?php echo h($status['message']) ?></p>
    <div id="BcBackupProgressBar" style="display:none; max-width:480px; height:14px; background:#e5e5e5; border-radius:7px; overflow:hidden;">
      <div id="BcBackupProgressInner" style="width:0%; height:100%; background:#4b8bf4; transition:width .3s;"></div>
    </div>
  </div>
</div>

<!-- 手動バックアップ実行 -->
<div class="section bca-section">
  <h2><?php echo __d('baser_core', 'バックアップ実行') ?></h2>
  <?php if ($canExec): ?>
    <p>
      <?php echo __d('baser_core', 'バックアップはバックグラウンドで実行されます。データ量が多いサイトでは時間がかかるため、定期実行には CLI（cron）の利用を推奨します。') ?>
    </p>
    <div class="bca-actions">
      <?php echo $this->BcAdminForm->create(null, ['url' => ['action' => 'execute'], 'style' => 'display:inline-block; margin-right:10px']) ?>
      <?php echo $this->BcAdminForm->hidden('type', ['value' => 'full']) ?>
      <?php echo $this->BcAdminForm->submit(__d('baser_core', 'フルバックアップ実行'), [
        'div' => false,
        'class' => 'bca-btn',
        'data-bca-btn-type' => 'save',
        'data-bca-btn-size' => 'lg',
        'confirm' => __d('baser_core', 'フルバックアップを実行します。よろしいですか？')
      ]) ?>
      <?php echo $this->BcAdminForm->end() ?>
      <?php echo $this->BcAdminForm->create(null, ['url' => ['action' => 'execute'], 'style' => 'display:inline-block']) ?>
      <?php echo $this->BcAdminForm->hidden('type', ['value' => 'diff']) ?>
      <?php echo $this->BcAdminForm->submit(__d('baser_core', '差分バックアップ実行'), [
        'div' => false,
        'class' => 'bca-btn',
        'data-bca-btn-size' => 'lg',
        'confirm' => __d('baser_core', '差分バックアップを実行します。よろしいですか？')
      ]) ?>
      <?php echo $this->BcAdminForm->end() ?>
    </div>
  <?php else: ?>
    <p>
      <?php echo __d('baser_core', 'この環境ではコマンド実行が許可されていないため、画面からのバックアップは利用できません。CLI（cron）をご利用ください。') ?>
    </p>
  <?php endif ?>
</div>

<!-- バックアップ一覧 -->
<div class="section bca-section">
  <h2><?php echo __d('baser_core', 'バックアップ一覧') ?></h2>
  <?php if ($listError): ?>
    <p><?php echo h($listError) ?></p>
  <?php elseif (!$backups): ?>
    <p><?php echo __d('baser_core', 'バックアップはまだありません。') ?></p>
  <?php else: ?>
    <table class="list-table bca-table-listup">
      <thead class="bca-table-listup__thead">
      <tr>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', 'ファイル名') ?></th>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', '種別') ?></th>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', '取得日時') ?></th>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', 'サイズ') ?></th>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', 'DB方式') ?></th>
        <th class="bca-table-listup__thead-th"><?php echo __d('baser_core', '操作') ?></th>
      </tr>
      </thead>
      <tbody class="bca-table-listup__tbody">
      <?php foreach($backups as $backup): ?>
        <tr>
          <td class="bca-table-listup__tbody-td"><?php echo h($backup['name']) ?></td>
          <td class="bca-table-listup__tbody-td">
            <?php echo ($backup['type'] === 'full')? __d('baser_core', 'フル') : __d('baser_core', '差分') ?>
          </td>
          <td class="bca-table-listup__tbody-td"><?php echo h($backup['created']) ?></td>
          <td class="bca-table-listup__tbody-td"><?php echo h(BcBackupUtil::humanSize((int)$backup['size'])) ?></td>
          <td class="bca-table-listup__tbody-td"><?php echo h((string)$backup['db_format']) ?></td>
          <td class="bca-table-listup__tbody-td">
            <?php echo $this->BcBaser->getLink(__d('baser_core', 'ダウンロード'), [
              'action' => 'download', $backup['name']
            ], ['class' => 'bca-btn', 'data-bca-btn-size' => 'sm']) ?>
            <?php echo $this->BcAdminForm->postLink(__d('baser_core', '削除'), [
              'action' => 'delete', $backup['name']
            ], [
              'class' => 'bca-btn',
              'data-bca-btn-type' => 'delete',
              'data-bca-btn-size' => 'sm',
              'confirm' => ($backup['type'] === 'full')?
                __d('baser_core', 'フルバックアップを削除すると、同じチェーンの差分バックアップもあわせて削除されます。よろしいですか？') :
                __d('baser_core', 'この差分以降の差分バックアップもあわせて削除されます。よろしいですか？')
            ]) ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <p>
      <?php echo __d('baser_core', 'リストアは CLI から実行できます:') ?>
      <code>bin/cake bc_backup restore &lt;<?php echo __d('baser_core', 'ファイル名') ?>&gt;</code><br>
      <?php echo __d('baser_core', 'リストア前に、現在の状態のバックアップを取得してから実行することを強く推奨します。') ?>
    </p>
  <?php endif ?>
</div>

<!-- 設定 -->
<?php echo $this->BcAdminForm->create($backupConfig, ['url' => ['action' => 'index']]) ?>
<h2><?php echo __d('baser_core', 'バックアップ設定') ?></h2>
<div class="section bca-section">
  <table class="list-table bca-form-table">
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('save_path', __d('baser_core', '保存先フォルダ')) ?>
      </th>
      <td class="bca-form-table__input">
        <?php echo $this->BcAdminForm->control('save_path', ['type' => 'text', 'size' => 60]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', '絶対パスで指定します。ドキュメントルート外を推奨します。') ?><br>
          <?php echo __d('baser_core', 'webroot 配下を指定した場合は .htaccess による直アクセス遮断を自動設置しますが、.htaccess が無効なサーバ（nginx 等）では保護されないため、必ず webroot 外を指定してください。') ?>
        </div>
        <?php echo $this->BcAdminForm->error('save_path') ?>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('targets', __d('baser_core', 'バックアップ対象ディレクトリ')) ?>
      </th>
      <td class="bca-form-table__input">
        <?php echo $this->BcAdminForm->control('targets', ['type' => 'textarea', 'rows' => 4, 'cols' => 60]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', 'サイトルートからの相対パスを1行に1つずつ指定します。') ?><br>
          <?php echo __d('baser_core', '例: webroot/files（標準） / webroot（webroot 全体） / config など任意のディレクトリを追加できます。') ?><br>
          <?php echo __d('baser_core', 'コア・プラグインのファイルは Git 管理または再インストールで復元可能なため、デフォルトでは対象外です。') ?>
        </div>
        <?php echo $this->BcAdminForm->error('targets') ?>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('db_method', __d('baser_core', 'DBバックアップ方式')) ?>
      </th>
      <td class="bca-form-table__input">
        <?php echo $this->BcAdminForm->control('db_method', [
          'type' => 'radio',
          'options' => [
            'standard' => __d('baser_core', '標準（コアAPI・全DB対応）'),
            'native' => __d('baser_core', 'ネイティブ（mysqldump / pg_dump / SQLiteコピー）')
          ]
        ]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', 'ネイティブ方式が利用できない環境では、自動的に標準方式へフォールバックします。') ?><br>
          <?php echo __d('baser_core', 'ネイティブ方式で取得した DB のリストアは手動での実行となります（自動リストアは標準方式のみ対応）。') ?>
        </div>
        <?php echo $this->BcAdminForm->error('db_method') ?>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('mysqldump_path', __d('baser_core', 'ネイティブコマンドパス')) ?>
      </th>
      <td class="bca-form-table__input">
        <small>[mysqldump]</small>&nbsp;
        <?php echo $this->BcAdminForm->control('mysqldump_path', ['type' => 'text', 'size' => 40]) ?><br>
        <small>[pg_dump]</small>&nbsp;
        <?php echo $this->BcAdminForm->control('pg_dump_path', ['type' => 'text', 'size' => 40]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', '自動検出した結果を初期値として表示しています。MariaDB 環境では mariadb-dump のパスを指定してください。') ?>
        </div>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('diff_mode', __d('baser_core', '差分検知モード')) ?>
      </th>
      <td class="bca-form-table__input">
        <?php echo $this->BcAdminForm->control('diff_mode', [
          'type' => 'radio',
          'options' => [
            'fast' => __d('baser_core', '速度重視（更新日時＋サイズ）'),
            'strict' => __d('baser_core', '厳格（ハッシュ比較。大量ファイルでは低速）')
          ]
        ]) ?>
        <?php echo $this->BcAdminForm->error('diff_mode') ?>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('keep_generations', __d('baser_core', '世代管理')) ?>
      </th>
      <td class="bca-form-table__input">
        <small>[<?php echo __d('baser_core', '保持世代数') ?>]</small>&nbsp;
        <?php echo $this->BcAdminForm->control('keep_generations', ['type' => 'text', 'size' => 6]) ?>
        <small>[<?php echo __d('baser_core', '保持日数') ?>]</small>&nbsp;
        <?php echo $this->BcAdminForm->control('keep_days', ['type' => 'text', 'size' => 6]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', '保持数はチェーン（フル＋連なる差分）単位で数えます。0 を指定すると無制限です。両方指定した場合は先に達した条件で削除されます。') ?>
        </div>
        <?php echo $this->BcAdminForm->error('keep_generations') ?>
        <?php echo $this->BcAdminForm->error('keep_days') ?>
      </td>
    </tr>
    <tr>
      <th class="bca-form-table__label">
        <?php echo $this->BcAdminForm->label('use_maintenance_mode', __d('baser_core', 'メンテナンスモード連携')) ?>
      </th>
      <td class="bca-form-table__input">
        <?php echo $this->BcAdminForm->control('use_maintenance_mode', [
          'type' => 'checkbox',
          'label' => __d('baser_core', 'バックアップ・リストア中にメンテナンスモードへ自動切替する'),
          'between' => '&nbsp;'
        ]) ?>
        <br>
        <?php echo $this->BcAdminForm->control('use_single_transaction', [
          'type' => 'checkbox',
          'label' => __d('baser_core', 'ネイティブ方式でトランザクション整合オプション（--single-transaction 等）を使用する'),
          'between' => '&nbsp;'
        ]) ?>
        <i class="bca-icon--question-circle bca-help"></i>
        <div class="bca-helptext">
          <?php echo __d('baser_core', 'メンテナンスモードは管理画面・管理者ログイン中・debug モードでは効かないため、完全な書き込み停止ではありません。') ?><br>
          <?php echo __d('baser_core', '--single-transaction が整合を保証するのは InnoDB のみです（MyISAM 混在時はメンテナンスモード併用が保険となります）。') ?>
        </div>
      </td>
    </tr>
  </table>
</div>

<div class="submit bca-actions">
  <div class="bca-actions__main">
    <?php echo $this->BcAdminForm->submit(__d('baser_core', '保存'), [
      'div' => false,
      'class' => 'bca-btn bca-actions__item bca-loading',
      'data-bca-btn-type' => 'save',
      'data-bca-btn-width' => 'lg',
      'data-bca-btn-size' => 'lg',
      'id' => 'btnSubmit'
    ]) ?>
  </div>
</div>
<?php echo $this->BcAdminForm->end() ?>

<!-- cron 設定支援 -->
<div class="section bca-section">
  <h2><?php echo __d('baser_core', '定期実行（cron）の設定例') ?></h2>
  <p><?php echo __d('baser_core', 'サーバの crontab に以下のように登録すると、毎日 3:00 に差分、毎週日曜 2:00 にフルバックアップを実行します。') ?></p>
  <pre><code># <?php echo __d('baser_core', '毎日 3:00 に差分、毎週日曜 2:00 にフル') . "\n" ?>0 3 * * 1-6 cd <?php echo h($rootPath) ?> &amp;&amp; bin/cake bc_backup run --diff >> /var/log/bc_backup.log 2>&amp;1
0 2 * * 0   cd <?php echo h($rootPath) ?> &amp;&amp; bin/cake bc_backup run --full >> /var/log/bc_backup.log 2>&amp;1</code></pre>
  <p><?php echo __d('baser_core', 'cron の MAILTO を設定すると、失敗時（非0終了）にメール通知を受け取れます。') ?></p>
</div>

<script>
$(function() {
  var statusUrl = $.bcUtil.adminBaseUrl + 'bc-backup/bc_backup/status';
  var running = <?php echo ($status['state'] === 'running')? 'true' : 'false' ?>;
  if (!running) return;
  var timer = setInterval(function() {
    $.getJSON(statusUrl).done(function(status) {
      $('#BcBackupStatusMessage').text(status.message);
      if (status.progress && status.progress.total > 0) {
        var percent = Math.floor(status.progress.done / status.progress.total * 100);
        $('#BcBackupProgressBar').show();
        $('#BcBackupProgressInner').css('width', percent + '%');
      } else {
        $('#BcBackupProgressBar').hide();
      }
      if (status.state !== 'running') {
        clearInterval(timer);
        // 完了メッセージをフラッシュ表示するため ?completed=1 を付けてリロードする
        location.href = location.pathname + '?completed=1';
      }
    });
  }, <?php echo (int)\Cake\Core\Configure::read('BcBackup.pollingIntervalMs', 3000) ?>);
});
</script>
