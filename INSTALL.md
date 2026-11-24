# c1.0.0 現行設置・更新手順（以下を旧履歴より優先）

新規設置は Initial Deployment ZIP、既存は Viewer Update ZIP（storage/seed/なし）。配布ZIPの独立SHA256を照合して隔離展開。既存の設定・DB・storageを保全し、公開を停止してアプリ更新。DocumentRootはpublic/のみ。Nginxのドットファイル拒否・PHP-FPM・HTTPS設定は運用者の既存設定で維持。

新規の場合、seed内にv3.0.0があります。既存の場合は公開ソースZIPを seed/ に別途配置してください。既存v2.6.1は再登録しない。

```bash
python3 tools/publish.py --verify-site
python3 tools/finalize_v3.py seed/DearYourFuture_v3.0.0_GitHub_Source.zip --sha256 1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f
python3 tools/finalize_v3.py seed/DearYourFuture_v3.0.0_GitHub_Source.zip --sha256 1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f --apply --approve-public
python3 tools/storage_admin.py audit
```

`--approve-public` は運用者が対象の公開範囲を確認してから実行。既存の v3.0.0 が同じSHAで登録済みなら再登録しない。MySQLモードの場合は保守停止を継続し、更新前後の移行計画を `docs/MYSQL_SYNC.md` の手順で同期・照合してから再開。自動フォールバック禁止。
新規設置はv3.0.0のみ。旧版の公開対象を再承認した場合に限り旧版から順に登録する別の手順を検討。既存旧版に今回除外された26パスが残るため公開範囲の再確認が必要。

以下は旧版の手順・開発履歴（現在のコマンドとして実行しない）。

# 設置・更新

PHP 8.4+（想定本番8.5）、Python 3（標準ライブラリ）、Linux。Document Root は **public/** のみ。Nginx/ApacheでPHP実行を index.php に限定し、他のディレクトリを公開しない。HTTPS有効化後にHSTS検討。Webユーザーは storage に書込みできない権限、CLI運用者は書込み可能にする。

初回配布ZIPを展開し、seed の公開用ZIPを権利・個人情報・秘密情報の観点からレビュー後、CLIで明示承認して登録する。

```sh
python3 tools/publish.py seed/KimiNoMirai_v2.6.1_GitHub_Source.zip --sha256 7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3 --check-only
python3 tools/publish.py seed/KimiNoMirai_v2.6.1_GitHub_Source.zip --sha256 7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3 --approve-public --message '[リリース] v2.6.1 - 安定版' --upstream-commit 693752f --upstream-parent 70003ed
php -S 127.0.0.1:8080 -t public
```

上記PHP開発サーバーはローカル検証専用。配布先本番には使わない。`config/site.php` でサイト情報設定。別ドメイン/専用公開ルートを使い、既存mina.c2s.meを上書きしない。

次版は新しい公開用 ZIP と対応SHA256を渡し、必要に応じ `--notes` で承認済みMarkdownを指定。過去版を再登録・上書きできない。旧版との実差分を保存。初期版のCompareは親版未収録なので表示保留。大容量・バイナリ差分は制限付き表示。任意の2版間比較は次期検討。

## 復旧
更新前に storage 全体（外部保存）をバックアップする。CLIはカタログ旧版もバックアップする。エラー時はWebが503を返す。ロックを削除して再インストールしない。別環境へ整合するバックアップを復元し検証後切り替える。復旧CLI/障害注入試験は次回。現版を本番更新用の無条件上書きZIPとして扱わない。

## v0.2.0 の比較・移行
比較元は比較先より先に登録した版を選ぶ。隣接版と非隣接版の差分を取り込み時に生成し、旧版の内容は書き換えない。最大21版までの初期設計（それ以上は容量・計算負荷を再設計するまでCLI拒否）。既にv0.1.0で複数版を収録済みの場合、旧非隣接比較データは未生成と表示される。初期v2.6.1のみの新規設置は新CLIで登録する。既存運用環境への更新は別途隔離検証が必要。行差分は大容量・バイナリを除外する。独自のスナップショット履歴でありGit履歴そのものではない。

## v0.3.0 の変更
公開サイト側の表示コードのみを変更。既存の公開版スナップショット形式（schema 1）・公開用ZIPは不変。旧版環境への本番更新や復旧は未検証のため、初期設置ZIPを既存稼働環境に直接展開しない。隔離環境で先に検証すること。MySQL基盤は未実装。

## v0.4.0 更新中断・バックアップ
運用者CLIのみ。新規版登録の公開承認後、journal作成後に異常終了した場合は Web が503を返す。別の公開版を登録せず、`python3 tools/publish.py --recover` で同じ検証済み登録を再開する。journal・カタログ・ロック・ステージが不整合なら拒否。ロックを削除しない。`--test-fault` は隔離試験専用。

```sh
python3 tools/storage_admin.py audit
python3 tools/storage_admin.py backup --dest /absolute/external/new-backup
python3 tools/storage_admin.py verify --source /absolute/external/new-backup
python3 tools/storage_admin.py restore --source /absolute/external/new-backup --dest /absolute/external/new-isolated-storage
```
backup/restoreの宛先はアプリ外かつ未存在のパス。バックアップは公開データ・カタログ・設置/更新ロックで構成し、過去のバックアップや一時ロックは重複収録しない。別途アプリの配布ZIPと設定も運用者が保全する。隔離復元後の本番切替は運用者の受入・停止・権限確認を前提とし、自動上書きしない。復旧処理はロールフォワードのみで、検証済みバックアップからの隔離復元が別手段。電源断・実サーバー復旧は未検証。
MySQL設計は `docs/MYSQL_DESIGN.md` を参照。SQLは設計案であり実行しないこと。既存の就活支援サイトDBは対象外。

## v0.5.0 比較容量と隔離MySQL移行
旧記述の最大21版制限は撤廃。登録時に隣接版を優先し、最大20比較を前計算する。`--compare-base vX.Y.Z` を繰返し指定すると、収録済みの古い版を優先する。未生成ペアは明示して表示し、実Git履歴を捏造しない。比較1組の詳細JSON予算8MB、1回の登録で16MB、1ファイル詳細2MB。計算量は行数積等の見積りで1ファイル400万、1登録3000万を上限とし、長大行・大容量・バイナリ・予算超過差分は明示して省略。メタデータ・省略マーカー・ZIP等は詳細JSON予算に含まない。既存比較データは書き換えない。任意の未生成比較の再構築CLIは次期。

隔離移行の準備（ライブ公開方式は不変）：

```sh
python3 tools/mysql_plan.py --output /absolute/external/new-plan.json
php tools/mysql_migrate.php --plan=/absolute/external/new-plan.json --sha256=EXPECTED_SHA256
```

既定はオフライン検査のみ。実DB試験は未実施であり、現時点で本番適用しない。隔離試験時のみ、専用の空DB `codepublication_rehearsal` と最小権限の運用者を用意し、`CP_MYSQL_DATABASE`、`CP_MYSQL_HOST`、`CP_MYSQL_PORT`、`CP_MYSQL_USER`、`CP_MYSQL_PASSWORD` を安全な環境設定から渡した上で `--apply --confirm-isolated-db` を指定する。既存の就活支援サイトDBは絶対に指定しない。DDL途中停止の場合は調査し、別の空DBで再試験する。WebのDB切替・同期・DB復元は今後の作業。

## v0.7.0 未生成比較の追加と容量確認
```sh
python3 tools/rebuild_comparison.py --base v1.0.0 --target v2.0.0 --confirm
python3 tools/storage_admin.py capacity
```
上記版番号は例。登録済み・前後関係が正しいペアだけが対象。既存比較は上書きせず、原本・カタログ不変。派生比較は `storage/derived_comparisons/<target>/<base>` に格納し、ハッシュ台帳と監査を経て原子的に登録。デフォルト派生比較累積上限512 MiB（`--max-storage-mib` 1〜4096）、空き32 MB未満を拒否。上限は派生比較のみで、原本・登録時比較の総容量ではない。バックアップと復元の対象に含む。異常停止でsystem以下の一時ステージが残った場合は運用者が調査し、同じペアの再実行前に確認する。既存の稼働コード更新は別途隔離受入が必要。

## v0.7.0 注意事項
新規登録でfiles_sha256・tar_sha256をカタログに保存。旧版のtar.gzは信頼できるハッシュ未登録の場合503で停止する。既存の正本を直接書き換えず、検証済み公開ZIPから隔離環境に再登録し、バックアップ・受入確認後に移行する（既存版の自動移行は未実装）。Web公開は権利・プライバシー審査終了後のみ。public/以外をDocumentRootとしない。PHP-FPMは読取り専用権限、CLI運用者のみstorage書込み権限。


## v0.8.0 UI受入手順（隔離環境のみ）
PHP構文・HTTP試験・Node模擬DOM試験は `node tests/test_ui.js`、`python3 tests/verify_ui_http.py http://127.0.0.1:8080`（v2.6.1の検証登録済みを前提）。実ブラウザでは docs/UI_ACCEPTANCE.md を実施。初期設置ZIPを稼働環境に上書きしない。MySQL実DB・公開可否は保留。

## v0.9.0 Nginx Rewrite（リリース必須）
`deploy/nginx-rewrite.conf` は既存の対象サイトの server コンテキストに適用する Rewrite 部分のみ。既存の `location /` がある場合は重複定義せず、対応する `try_files` を調整する。ドメイン直下の設置を想定。クエリ文字列を維持する。

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

前提：DocumentRoot は public/、index は index.php、既存のPHP-FPM設定は index.php のみ実行可能、ドットファイル等を拒否する。SSL・ドメイン・PHP-FPM・全体設定はこの断片に含めない。変更前にバックアップ、`nginx -t`、隔離環境で HTTP と PHP 実行の確認を行い、成功時のみ既存運用手順でリロードする。本番Nginxへの適用は未実施。

## v0.9.0 比較データの旧形式移行
既存のスナップショット・カタログを直接上書きしない。旧版は比較のハッシュ台帳がない場合、比較閲覧を拒否する。旧環境のアプリ・storageを外部にバックアップし、整合性を監査する。各版の公開承認済み原本ZIPと独立したSHA256台帳、元のリリース情報を用意し、新しい隔離環境に古い順に登録する。登録時に新規比較ハッシュ台帳を生成し、タグ・履歴・件数・公開ZIPのハッシュを照合する。必要な未生成ペアを再構築し、HTTP・配布・復元を検証する。公開時刻等の元情報と新規登録時刻が異なる可能性を記録する。原本がない版や検証不一致は移行保留。受入・切替・ロールバックは運用者が実施する。自動移行CLI・既存運用環境への上書き更新は未実装。

## v0.10.0 旧形式の隔離再構築CLI（本番公開ではない）
`tools/rehearse_legacy.py` はアプリ外の停止済み旧storageコピーのみを読取り、新規の隔離ディレクトリにstorageとrehearsal-report.jsonを生成する。公開元の独立したSHA256台帳を確認し、全タグを過不足なく記録したJSON `{"schema":1,"archives":{"v2.6.1":"7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3"}}` を用意する。台帳は旧カタログの値だけから作らない。台帳のSHA256も別途照合する。

```sh
python3 tools/rehearse_legacy.py --legacy-storage /absolute/offline/old-storage --ledger /absolute/reviewed-ledger.json --sha256 EXPECTED_LEDGER_SHA256 --dest /absolute/new-rehearsal --confirm-rehearsal
```

新規宛先のみ許可。登録順、元のメッセージ、日付、原本ハッシュ、ファイル内容、既存比較のカバーを照合し、登録時刻とtar/差分ハッシュは再生成される。旧ファイルを直接変更しない。隔離出力を本番へ自動切替しない。旧v0.6.0の実1版と合成3版で検証済。全実版の移行・本番ロールバックは未受入。公開前チェックは `docs/RELEASE_GATE.md` を参照。Nginx Rewriteは `deploy/nginx-rewrite.conf` のみを同梱。

## v0.11.0 配布前検査
展開前に、信頼できる独立SHA256台帳を用意し、検証済みのCLIを隔離環境で実行する。
```sh
python3 tools/preflight.py /path/to/CodePublication_v0.11.0_Initial_Deployment.zip --sha256 <SHA256>
```
package PASSは配布構造の検査のみ。release_gateはHOLDを維持する。実環境の権利・設定・DB・ブラウザ確認を代替しない。Nginx Rewrite断片のみ同梱。

## v0.12.0 運用CLI統合（隔離試験用）
`tools/accept_http.py` / `tools/mysql_sync.php` を同梱。実行条件・使用法は `docs/HTTP_ACCEPTANCE_ROLLBACK.md` と `docs/MYSQL_SYNC.md` を参照。内部作業完了前の外部実行は禁止。実DB接続を行わない既定モードを維持。Nginx Rewrite断片のみ `deploy/nginx-rewrite.conf` に収録。

## MySQL読み取り候補（v0.13.0）
既定はfiles。外部受入前にmysqlへ切り替えない。専用SELECTアカウント・環境変数・復旧制約はdocs/MYSQL_READER.md。Nginxは既存Rewrite断片のみ。

## v0.14.0 外部受入用候補
内部完了後の外部工程では docs/INTERNAL_EXTERNAL_GATE.md の順序を守る。DB復旧・検証は docs/MYSQL_RECOVERY_AND_ACCEPTANCE.md、実ブラウザは docs/UI_ACCEPTANCE.md、素材確認は docs/PUBLICATION_FINAL_REVIEW.md。既存のNginxでドットファイルを拒否すること（PHP内蔵サーバー単体は.htaccessを配信し得る）。Rewrite断片のみ deploy/nginx-rewrite.conf。外部試験合格・公開承認まで公開禁止。

## v0.15.0 作者・ロゴ
public/assets/author-avatar.png と public/assets/project-favicon.ico を静的配信する。配布前検査の公開ファイル許可リスト更新済み。Nginxの既存index.phpとPHP実行経路、ドットファイル拒否を確認。Rewrite断片以外のNginx全設定は配布しない。HTTP受入39項目、ブラウザ計画75項目。

## v0.16.0 稼働済みコード公開サイトのアプリ更新（既存storageを保持）
初期設置用ZIPではなくViewer_Update ZIPを使用する。検証済みSHA256台帳でZIPを照合し、アプリ外の隔離ディレクトリへ展開して preflight.py で検査する。既存アプリ・config・storage・DB（使用時）をバックアップし、公開停止後に更新ZIPのファイルを反映する。Viewer_Updateにはstorage/・seed/を含まない。旧アプリのconfig/site.phpの独自設定は新しい日本語プロフィールを維持しながら必要項目だけマージし、変更内容を記録。既存のDocumentRootはpublic/。Nginx Rewrite rulesは変更なし。

```sh
python3 tools/publish.py --verify-site
python3 tools/storage_admin.py audit
```

明示検証は配布時のconfigとの差異も報告する。意図した設定変更であれば内容を確認すること。HTTP応答 `X-CodePublication-Version: 0.16.0` とフッターv0.16.0、新しいアセットURL `?rev=...` を確認。古い表示が続く場合は対象DocumentRoot・PHP-FPM OPcache・キャッシュを運用者側で確認し、既存手順でPHP-FPMを再読込、ブラウザの強制再読込。アプリ更新のみなら既収録版を再publishしない。v2.7.4の公開用ZIPが発行されるまでv2.6.1を維持する。

## c1.0.0-rc.1（既存設置からの更新）
現行 app/config/public/ のバックアップと storage/ の検証・バックアップを取得。Viewer_Update ZIP を別領域へ展開し `tools/preflight.py` でSHA照合済みの配布物を検証。公開停止・既存の設定を確認した上で対応ファイルを適用（storage/ と seed/ を上書きしない）。`python3 tools/publish.py --verify-site`、`python3 tools/storage_admin.py audit`、HTTPヘッダー `X-CodePublication-Version: c1.0.0-rc.1` を確認する。既存 v2.6.1 を再登録しない。

サイト開発側のv3.0.0公開原本を入手したら、SHA256の期待値を独立した配布台帳から取得し、下記のプレースホルダーを置換する。非公開用ZIPは指定しない。

```sh
python3 tools/publish.py /path/to/DearYourFuture_v3.0.0_GitHub_Source.zip --sha256 EXPECTED_SHA256 --check-only
# 公開素材の権利・個人情報・PDFの公開許諾を確認した場合のみ実施。
python3 tools/publish.py /path/to/DearYourFuture_v3.0.0_GitHub_Source.zip --sha256 EXPECTED_SHA256 --approve-public --message '[リリース] v3.0.0 - Dear Your Future 正式版' --notes app/data/v3.0.0-release-notes.md
```

MySQLモードなら旧計画・新計画を使い既存同期手順を実行し、整合性試験後に公開を再開する。正式版発行は公開原本・資料・外部受入が完了してから。開発資料PDFは `docs/C1_DEVELOPMENT_RELEASE.md` の固定ファイル名を参照。NginxはRewrite断片のみ配布。Google Fontsは指定のHTTPS先に接続する。フォント取得を許可した外部ブラウザ受入では `--allow-google-fonts` を指定する。
