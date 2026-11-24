# コード公開サイト v0.11.0 R4 — MySQL同期リハーサル

基準：Google Drive v0.11.0 Source Repository / Initial Deployment ZIP。両方のSHA256照合、Source Repository内部49件照合済み。R1〜R3およびサイト本体のZIPは変更しない。独立作業規約・Nginx Rewrite rulesのみの同梱を維持。

## 今回の成果
- `mysql_sync.php`：既存のMySQL移行計画2件を独立SHA256で照合し、過去版の変更・削除を禁止、追加版と追加ファイル数を算出する。既定はDB接続なし。
- `--storage` 指定時、公開mutexを共有ロックし、現在のカタログSHA256、READY/install lock、未完了ジャーナルの不存在を確認する。これはカタログ照合であり、全ソース実体の再監査ではない。実体監査は既存 `mysql_plan.py` で別途実施する。
- `--apply --confirm-isolated-db` の組合せのみDB適用を許可する設計。専用ローカルDB `codepublication_*`、既存の5テーブル・InnoDB・schema checksum、既存のファイル側排他ロックとDB advisory lockを要求。既存DBと前計画の完全なリリース・ファイル・公開状態の読戻し照合後、追加行をトランザクションで投入し、generationと状態更新、イベント記録、読戻し、カタログ再確認、commitを行う。既に同期済みなら読戻しだけで終了する設計。異常時rollback、DROP/DELETE/DDLなし。DBの実行経路は未検証。

## 隔離試験の手順
1. v0.11.0を隔離展開し、`mysql_plan.py` で旧計画を作成、SHA256を独立保存。初回は従来の `mysql_migrate.php` を空の専用DBに適用する（実DB検証が別途必要）。旧計画は変更しない。
2. 新版を隔離環境に登録し、`mysql_plan.py` で新計画を作成・全実体を監査する。DB試験中は管理者の直接変更・外部writerを禁止する。
3. `php mysql_sync.php --previous=OLD.json --previous-sha256=OLD_HASH --target=NEW.json --target-sha256=NEW_HASH --storage=/isolated/app/storage` でオフライン確認。
4. 実DB試験はPHP 8.5/PDO MySQL/MySQL 8.4 LTSの隔離環境のみ。環境変数CP_MYSQL_DATABASE/HOST/PORT/USER/PASSWORDを安全に設定し、上記に `--schema=/isolated/app/database/migrations/001_catalog_design.sql --apply --confirm-isolated-db` を追加。ネットワーク公開前の専用DBのみ。秘密情報はログ・成果物へ保存しない。
5. 失敗時は本番切替を禁止。ファイル側の承認済みデータを保全し、DBを隔離したまま原因確認。commit応答が不明な場合、同じ2計画を再利用し状態と全行を照合する。既存DBの修復・削除は自動実行しない。必要なら別の空DBへ監査済み計画から再構築する。MySQLの物理・論理バックアップ復元と障害注入は次工程。

## 実測結果
PHP 8.4.24、PDOあり、MySQLドライバ・MySQLサーバーなし。PHP構文検証PASS。v0.11.0のpublish.py/mysql_plan.pyによる3版合成データを使用。追加2版/8ファイル、同一計画、逆順、SHA256不一致、既存履歴改変、古いカタログ、更新中ジャーナル、mutex競合、無承認、PDO不在の10試験すべてPASS。詳細はUnit_Test.log。SQL実行・トランザクション・DB障害復旧は未試験。Web側はファイルベース正本のまま。公開許諾・実ブラウザ・本番FPMは未完了。

## 次工程
実MySQL隔離試験（初回移行→同期→再実行→異常注入→DB復元）、DB正本化の設計・実装、Web側fail-closed切替、公開審査と本番受入。暫定進捗85%→86%（見積値）。本番変更なし。
