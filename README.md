# BcBackup

baserCMS 5 向けの自動バックアッププラグイン。

DB（MySQL / PostgreSQL / SQLite）とアップロードファイルのバックアップを、管理画面（GUI）と CLI（cron による定期実行）の両方から実行できます。

## 主な機能

- DB フルバックアップ
  - 標準方式: コア API によるスキーマ PHP＋CSV 出力（外部コマンド不要・全 DB 対応・自動リストア可）
  - ネイティブ方式: mysqldump / pg_dump / SQLite ファイルコピー（`VACUUM INTO` → `SQLite3::backup()` 自動フォールバック）
- files（アップロードファイル等）のフル／差分バックアップ
  - 差分はチェーン ID＋連番方式。速度重視（更新日時＋サイズ）／厳格（ハッシュ）の 2 モード
- 保存先フォルダ指定（デフォルト: `ROOT/backup/`。webroot 配下指定時は .htaccess を自動設置）
- 世代管理・ローテーション（チェーン単位。保持世代数／保持日数の併用可）
- 管理画面: 設定・手動実行（バックグラウンド実行＋進捗ポーリング）・一覧・ダウンロード・削除・crontab 記述例
- CLI: `run --full` / `run --diff` / `list` / `restore <name>`
- バックアップ・リストア中のメンテナンスモード自動切替（設定で変更可）
- 排他制御（flock による多重実行防止）・ディスク空き容量チェック・ZIP の原子的書き込み

## インストール

1. `plugins/BcBackup` を配置
2. 管理画面 > プラグイン から「バックアップ」を有効化（`bc_backup_configs` テーブルが作成されます）
3. 管理画面 > プラグイン > バックアップ で設定を確認

## 使い方

### CLI

```
bin/cake bc_backup run --full          # フルバックアップ
bin/cake bc_backup run --diff          # 差分バックアップ（files のみ差分、DB はフル）
bin/cake bc_backup list                # バックアップ一覧
bin/cake bc_backup restore <name>      # リストア（確認プロンプトあり。--force でスキップ）
```

### 定期実行（cron）

```cron
# 毎日 3:00 に差分、毎週日曜 2:00 にフル
0 3 * * 1-6 cd /path/to/app && bin/cake bc_backup run --diff >> /var/log/bc_backup.log 2>&1
0 2 * * 0   cd /path/to/app && bin/cake bc_backup run --full >> /var/log/bc_backup.log 2>&1
```

失敗時は stderr へ出力し非 0 終了するため、cron の `MAILTO` によるメール通知がそのまま機能します。

## リストア

- **標準方式の DB**: `bc_backup restore <name>` で自動リストアされます（drop → create → CSV 投入 → シーケンス更新）
- **ネイティブ方式の DB**: 自動リストア対象外です。restore 実行時にダンプファイルを取り出し、手動リストア手順（`mysql < dump.sql` / `psql < dump.sql` / SQLite ファイル差し替え）を表示します
- **files**: チェーン（フル＋差分）を連番順に上書き展開し、各差分の削除ファイル一覧を適用します

### 手動での files 展開手順（GUI 利用者向け）

1. 対象チェーンのフル ZIP と、復元したい時点までの差分 ZIP をダウンロード
2. フル ZIP 内の `files/` ディレクトリをサイトルートに上書き展開
3. 差分 ZIP を連番順に同様に上書き展開
4. 各差分 ZIP 内 `manifest.json` の `deleted` に列挙されたファイルを削除

## 保存形式

```
backup/
  20260712_020000_full.zip                        # チェーンの起点（フル）
  20260713_030000_diff_20260712_020000_001.zip    # 同チェーンの差分 #1
  catalog.json                                    # 管理台帳（DB には持たない）
```

各 ZIP には `manifest.json`（種別・チェーン ID・連番・ファイル一覧・削除一覧・バージョン情報）を同梱しています。`catalog.json` が壊れた場合は ZIP のスキャンから自動再構築されます。

## 注意事項

- バックアップファイルには全個人情報が含まれます。Web からアクセス可能な場所への保存は避けてください（webroot 配下指定時の .htaccess 保護は nginx 等では機能しません）
- メンテナンスモードは管理画面・管理者ログイン中・debug モードでは効かないため、完全な書き込み停止ではありません
- `--single-transaction` が整合を保証するのは InnoDB のみです（MyISAM 混在時はメンテナンスモード併用が保険となります）
- 別サーバ保存が必要な場合は、保存先ディレクトリを NFS / マウント済みストレージにすることで実質的に対応できます（S3 等への自動転送は将来対応予定）

## Thanks
- [http://basercms.net](http://basercms.net/)
- [http://wiki.basercms.net/](http://wiki.basercms.net/)
- [http://cakephp.jp](http://cakephp.jp)

## License
[MIT License](LICENSE)
