# MySQL 更新中断・隔離復旧・外部受入手順 v0.14.0

## 内部設計の確定
ファイル側の更新ジャーナルは publish.py が管理する。MySQLモードは既存の共有mutex、READY、カタログSHA、DB公開状態および投影一致が成立するまで503。ファイル更新とDB同期は単一分散トランザクションではない。運用者がメンテナンス状態を保持し、下記を順に実施する。既定のfilesモードや別サイトには自動的に切り替えない。

1. 承認済み旧版を監査し、`python3 tools/mysql_recovery_bundle.py create --bundle /offline/old-bundle` でストレージと計画を同一排他ロック下で取得。出力されたmanifest SHAを別の信頼できる台帳に保全する。計画・バックアップを公開ディレクトリへ置かない。DB側の完全論理バックアップは別途実施する。
2. 隔離環境のpublish.pyで承認済み新版を登録。途中停止時はジャーナルを保全し `publish.py --recover`、監査、旧版と新版の計画を再作成。ジャーナルやロックを手動削除しない。新計画のSHAも独立保全。
3. `mysql_sync.php` のオフライン比較、カタログ・ロック検証後、承認を得た隔離DBで適用。既存版の改変・削除は拒否する。DBのアドバイザリロック、行ロック、トランザクション、読戻し照合を要求する。commit前の異常はrollback、commit後の応答不明は同じ旧・新計画で再照合。新状態なら全行読戻しのみ、旧状態なら追加同期を再試行。いずれでもなければ公開停止、手動調査。実DBでのトランザクション検証は外部工程。
4. 旧バンドルから新規隔離復元: `python3 tools/mysql_recovery_bundle.py restore --bundle /offline/old-bundle --sha256 EXPECTED_MANIFEST_SHA --dest /offline/restored-pair`。復元先には storage/ と plan.json を生成。既存領域への上書き禁止。独立ハッシュでmanifestと全収録ファイル、原本・tar・索引・差分・復元計画を監査。新しい空の専用DBへ `mysql_migrate.php --plan=... --sha256=... --apply --confirm-isolated-db` で投影を再構築し、読戻しを確認する。元のDBを削除しない。
5. 読取りCLI `php tools/verify_mysql_reader.php --storage=/isolated/storage --confirm-isolated-reader` は専用test DBに別のSELECT専用アカウントを指定し、全版のカタログ・ファイル索引・ファイル・ZIP/tar・隣接変更一覧を照合する。本番切替は実行しない。
6. 元のDBのイベント履歴・物理ID・世代・付与権限は投影復元の対象外。完全な監査履歴が必要な場合は、外部環境でメンテナンス停止中に専用DBの整合した論理/物理バックアップを別に取得し、運用者が別の空DBで復元・権限・履歴・ハッシュ・読取りを検証する。DB管理者アカウント、秘密値、ダンプを公開物に含めない。復元時の切替は対応するアプリ、DB、storage、設定を一組にして運用者が実施する。実DB完全バックアップの復元実測は外部工程。

## 実DB統合スクリプト（外部工程専用）
`python3 tools/rehearse_mysql.py --confirm-two-empty-isolated-test-dbs`。MySQL 8.4 LTS、PHP 8.5/PDO MySQL、ローカル専用の相互に異なる空DB `codepublication_test_*` 2個が必要。CP_MYSQL_DATABASE / CP_RESTORE_MYSQL_DATABASE / CP_MYSQL_HOST / CP_MYSQL_PORT / CP_MYSQL_USER / CP_MYSQL_PASSWORD と別ユーザー CP_READER_USER / CP_READER_PASSWORD / CP_READER_HOST / CP_READER_PORT を安全に設定。DB名はスクリプトが各試験に設定。DB作成・権限付与は外部運用者が実施し、書込みユーザーとSELECT専用ユーザーを分離。許可されたローカル隔離DB以外には接続しない。既存テーブルありの場合は停止。合成2版の初回登録、同期前中断、再試行、commit後応答喪失、冪等再実行、実Reader、バンドル作成・復元、別DB再構築・読戻しを順に検証する。障害注入フラグは明示applyと専用test DBに限定。実DBを自動消去しない。スクリプトの実DB経路は未実行。内部試験の合格はSQL実行や権限の実測を意味しない。

## 内部検証
復旧バンドル3件（往復・改変・競合）、実行条件等5件、公開素材台帳1件、従来40件、計49件PASS。PHP 8.4.24にPDO MySQLなし。実DB・PHP-FPM・実ブラウザ試験はHOLD。
