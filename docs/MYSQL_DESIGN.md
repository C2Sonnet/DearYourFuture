# MySQL移行設計・隔離移行CLI v0.5.0

- コード公開サイト専用の独立DB。サイト開発DB・情報収集データは接続・変更しない。MySQL 8.4 LTS候補、PDO MySQL、utf8mb4。
- 5テーブルの設計はv0.4.0を継承。`database/migrations/001_catalog_design.sql` は隔離試験用。DB正本への切替は未実装。
- `tools/mysql_plan.py` は公開更新mutexの下で、カタログ・ロック・原本ZIP・tar・ファイル実体を監査し、全公開版の親参照、最終変更版・ハッシュ・サイズを照合した移行計画を作る。出力先はアプリ外の新規ファイル。
- `tools/mysql_migrate.php` の既定は計画のSHA256と構造・参照検証のみ。DB接続なし。明示的に `--apply --confirm-isolated-db` を付けた場合だけ隔離DBへ接続する。PDO MySQLが必須。専用ローカルDB名 `codepublication_*` に限定し、既存テーブルが1件でもあれば拒否。DB名と排他アドバイザリロックを確認。
- DDL適用後、リリース・親・ファイル・最終変更参照・メタデータ・状態の投入と読み戻し検証をトランザクションで行う設計。DDLはトランザクションで一括復旧できない。異常時は隔離DBを保持し、再適用での上書きや自動DROPは行わない。
- `published_at` は外部公開日時の捏造を避けるため現行案では登録日時(imported)をUTCで格納し、元公開日時はmetadataのdateに保持する。別途列名・意味を本番設計で確定する。
- 接続情報は環境変数から取得。DBの秘密情報を成果物に含めない。MySQL実接続、DDL、投入、復元、Web読取り切替、ファイルとの更新整合性は未検証・未完了。PHP 8.4.24・PDOドライバ0件を実測。
- 本番切替前：専用DB権限・バックアップ/隔離復元・実DBでの読み戻し・DB正本更新ジャーナル・障害注入・Web読取り切替を別Roundで実装し受入。現行ファイル方式schema 1を維持する。

## v0.12.0
R4の同期CLI tools/mysql_sync.php を統合。オフライン試験10件合格。docs/MYSQL_SYNC.md参照。Web正本化は内部残作業、MySQL実環境試験は内部完了後の外部作業。

## v0.13.0
Web読み取り候補を実装。既定files、mysql明示時にDBメタデータとストレージの一致を要求。詳細docs/MYSQL_READER.md。実DB検証・更新中断復旧・正式切替は未完了。

## v0.14.0 復旧・統合受入
監査済み投影計画とストレージを同一ロックで保存する復旧バンドル、別領域復元、前/後commit障害注入（専用test DBのみ）、実MySQL統合スクリプト、SELECT専用Reader検証CLIを追加。分散トランザクションではなく、503と保全・再照合による明示的復旧。DBイベント等の完全復元・実SQL試験は外部工程。docs/MYSQL_RECOVERY_AND_ACCEPTANCE.md参照。
