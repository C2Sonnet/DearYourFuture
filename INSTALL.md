# インストールと安全な更新 — v2.0.0（検証候補）

## 前提条件

- PHP 8.4以上、PDO MySQL、Argon2id、OpenSSLなどサイトが必要とするPHP拡張。
- MySQLにテーブル作成・初期データ挿入権限を持つ専用DBアカウント。新規設置DBは**完全に空**である必要があります。
- HTTPS、セッション／ファイル保存が可能なWebサーバー。ドキュメントルートは**必ず `public/`**。
- `app/`, `database/`, `docs/`, `resources/`, `storage/`, `waterfall/` 等を直接HTTP公開しない構成。

## 新規設置（データ同時登録）

1. ZIPを解凍し、上記のドキュメントルートで配置。`public/.user.ini` は環境固有のため配布していません。必要ならサーバー管理者が別途作成してください。
2. PHP CLIで `php tools/install/check-install-package.php --json`。`package.ok=true`、`package.checked=13` であることを確認。これは読み取り専用です。
3. `storage/` の親ディレクトリおよびサイトルートを初期設置中のみPHPプロセスから書込み可能にし、非公開ディレクトリの権限を適切に設定します。ディレクトリを公開URLからアクセスさせないこと。
4. `php tools/install/create-token.php` を実行して60分間有効の初回セットアップトークンを発行。出力値は秘密として扱う。
5. ブラウザでHTTPSの `/initial` を開き、初期開発者、サイトURL、空の専用DBの設定を入力。
6. 設置処理はSchema41のテーブルを構築した後、**初期企業SQL・R06研究SQLを自動的に実行**し、非公開の会社・求人データを登録します。別の手作業による研究SQL導入は不要です。
7. 完了後にログインし、役割・メール設定・表示内容を確認します。表示カタログはサイト採用済みR07 Batch50の112事業者・143求人を正本として持ちます（研究側の審査状態は別）。

## インストール停止・既存DBの保全

「安全のため設置を停止しました」と出た場合、`install.incomplete`・DBテーブル・`storage/private`・設置ロックを削除して再インストールしてはいけません。元環境をバックアップして保護し、次の読み取り専用診断を使用します。

```bash
php tools/install/recovery-plan.php --json
php tools/install/check-install-package.php --json
php tools/install/inspect.php
```

ログの `KMM install refused stage=... type=... mysql_errno=...` を確認し、管理者が別環境で復旧計画を検証します。MySQLの実環境での復旧は、この配布ZIPのオフライン検査だけでは保証できません。

## 既存サイトの更新（データ保護）

1. 設置中、復旧中、部分適用状態のサイトは上書きしません。
2. MySQLの**整合したバックアップ**と `storage/private`, `storage/system` を安全にバックアップし、復元可能性を別環境で確認します。
3. 現行版・DB Schema・署名付きリリースの出所・SHA256を確認してください。**GitHubダウンロードZIP自体は自動更新に必要な署名付き差分ではありません**。
4. 署名・ロック・バックアップ検証済みのアップデータでのみ更新し、必要な移行の前後を監査します。
5. 更新後にサイト表示・ログイン・求人閲覧・本人データ・出力機能を回帰確認します。

**重要**：既存DBの再作成、初期SQLの再投入、`.user.ini`／`storage` の消去、単純な全ファイル上書きはしません。ユーザー編集済み企業・求人、応募履歴、メッセージ等は保持対象です。

## ウェブサーバー

nginxの参考ルーティングは `deploy/nginx-try-files.example`。Apacheを利用する場合も、Webルートを `public/` に制限し、非公開ディレクトリを公開しないでください。robots設定はセキュリティ認証の代替ではありません。

## 安全なCLI更新（検証候補）

`update.php` を `public/` の親ディレクトリに配置し、同じディレクトリに署名済みRelease ZIPを置きます。更新パッケージの提供者から別の経路でSHA256とEd25519署名・公開鍵を取得してください。

```bash
php update.php --zip=RELEASE.zip --sha256=TRUSTED_SHA256 --plan --db-compare
php update.php --zip=RELEASE.zip --sha256=TRUSTED_SHA256 --signature=RELEASE.sig --pubkey=storage/private/release_public_key.hex --apply --confirm
```

アップデータは実行前のDB全体を`mysqldump`で保護し、リリースが明示的に許可した同一SchemaのDMLのみトランザクションで処理します。**現行DBを空にして全面再構築するモードはありません。** DBの全置換には別途、専用のシャドー環境で保存対象全表・参照制約・復元を実証した移行計画が必要です。失敗時に自動復元できなければ復旧停止を維持します。

ロックファイルはサイトルートの`install.lock`、`update.lock`で管理します。旧設置の移行では`storage/system`の既存ロックを読み取り互換として扱います。署名やマニフェストの検証を回避しないでください。

**v2.0.0注意：** DDLを含むスキーマ変更に対する自動復旧は、この候補版のCLIでは未対応です。実MySQL検証を経ずに署名・本番適用許可を付与しないでください。
