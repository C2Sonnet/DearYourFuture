# c1.0.0 現行記録

# c1.0.0 — 正式版配布・公開原本登録

2026-11-25 JST。対象サイト開発版 v3.0.0。
- ユーザー報告により前候補版のオンライン検証完了を記録。今回の追加変更は隔離環境で検証。本番へ接続・設置・DB変更は未実施。
- サイト開発側の公開承認済み原本（310ファイル、manifest309件、PDF8点、公開側記録70ページ）SHA256 `1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f`。公開専用・非設置用・未署名。
- Drive原本：https://drive.google.com/file/d/14N3fk-7QGCismuke-DTZ0fgj4_0vvkD0/view
- 公開ソース検証記録：https://drive.google.com/file/d/1OMGYbWujmGUUWXzjYyIVcnM6iN36AiuX/view
- `resources/private/waterfall/` の8指定PDFとSHA256SUMS.txtだけを、このSHA256に固定して例外許可。他のprivate/waterfallパスは引き続き拒否。旧 `docs/development/` 互換維持。原本は変更しない。
- 文書は11/18〜21付の歴史的設計資料。テスト報告書の想定検収をv3.0.0実測結果として扱わない。
- main最新タグ v3.0.0。既存v2.6.1から隔離登録したID `1e48f8dbb23a`（SHA由来、実Git IDではない）。追加11、変更39、削除26、差分制限13。
- 新規設置のseedはv3.0.0のみ。旧版を追加同梱しない（新公開版で除外された26パスが旧版に存在する）。既存サイトの履歴は上書き・削除しない。旧版・比較・旧ZIPから過去の情報が見えることに注意し、公開範囲は運用者が再確認。
- 第三者権利・個人情報の独立証明ではない。公開承認済み原本の承認記録を採用。今回の追加変更の本番再受入、実DB正本化・同期は運用者の環境で確認する。
- Nginx Rewriteのみ同梱。Google Drive限定永続保存。サイト開発・情報収集側は変更なし。

以下は旧版の履歴・旧判定です。最新判定・手順を優先してください。

# UI受入マトリクス v0.8.0

2026-11-24。実ブラウザによる受入は未完了。

| 項目 | 確認方法 | 状態 |
|---|---|---|
| CSSの構文 | tinycss2でトップレベル規則を解析 | PASS |
| 幅320 / 390 / 768 / 1024 / 1200px | 実画面でページ全体横溢れ・パネル・文字折返し・ポップアップのはみ出しを実測 | HOLD（5幅とも未測定） |
| テーマ3モード・無効値・設定拒否・OS追従ラベル・別タブ同期 | Node vm模擬DOM | PASS（ロジックのみ） |
| テーマの色反映、再読込時の点滅、実OS変更 | Chromium/Firefox/モバイル実機 | HOLD |
| Back to top | Node vmでscrollTo/フォーカス/動き抑制を確認 | PASS（ロジックのみ） |
| Back to topの実スクロール・キーボード・タップ | 実ブラウザ | HOLD |
| 検索結果・Escape・外部クリック | Node vm | PASS（ロジックのみ） |
| 検索ラベル、フォーカス先、ID一意性、CSP | 13の実ソースHTTP HTML | PASS |
| スクリーンショットと実装画面の対照 | 実ブラウザで全対象画面を撮影 | HOLD |
| WCAGコントラスト、スクリーンリーダー、ズーム200% | 実ブラウザ・支援技術 | HOLD |
| コピー、ネイティブdetailsのタッチ/Enter/Space | 実ブラウザ | HOLD |

## 実ブラウザ受入の手順
隔離サーバーに公開許諾済みのテストデータを取り込む。幅別にCode/Branches/Commits/Commit/Compare/Tags/Releases/READMEを開く。横幅はdocumentElement.scrollWidth <= innerWidthを確認（コード表の内部スクロールは許容）。各ポップアップ、各検索・ダウンロード・フォーカス操作を確認し、ConsoleでCSP違反を確認する。テーマはOS/手動/再読み込み/端末同期を確認。比較画面はテスト用2版以上で確認する。データの値や機密をスクリーンショットで共有しない。確認未実施をPASSとしない。

## 実行環境の制約
Chromium起動可能、ローカルURLへの遷移は net::ERR_BLOCKED_BY_ADMINISTRATOR。これを回避せずHOLDとする。CSSは静的解析、HTTPは通常のリクエスト、JSは模擬DOMのみ。

## v0.14.0 外部受入用スクリプト
`python3 tools/accept_browser.py --describe` は外部接続なしで計画を表示。`python3 tools/accept_browser.py https://YOUR-ISOLATED-HOST --confirm-owned-isolated` はPlaywright Chromiumを用いて5幅×14項目（9経路のレイアウト、テーマ/再読込/OS反映ラベル、検索、キーボードdetails、先頭復帰、JS/CSP）を実行。指定オリジン以外のリクエストを拒否。管理ポリシーを回避しない。実ブラウザで実行していないため70項目は計画数。コピー、タッチ、モバイル実機、支援技術、ズーム、色対比、Firefox、実Git差分の最終確認は外部手動受入。スクリーンショット自動保存なし。正式公開HOLD。

## v0.15.0 作者・ロゴ・日本語UI
外部ブラウザ計画は5幅×15項目=75。追加1項目/幅：日本語lang、favicon、プロジェクトロゴの読み込み、作者名、プロフィールの開閉、公式サイトリンクとアバター画像ロード。実ブラウザ未実行。HTTP構造13/13・隔離Nginx HTTP39/39・両画像の実配信SHA256一致。

## v0.16.0 追加要件
5幅×17項目=85の自動受入予定。初期dark・2状態のみ・OS非依存、ライセンス／セキュリティ方針のアンカー、Highlight.jsトークン、ポップオーバー矩形・作者画像の確認を含む。実ブラウザはHOLD。Highlight.jsは11.0.1の同梱実物で暫定採用、最新版の取得は外部作業。

## c1.0.0-rc.1 追加受入
5幅×19項目（95項目予定）。開発資料タブの表示・4工程・8文書・フォント指定・レスポンシブを追加。Google Fontsの実取得は `--allow-google-fonts` を付ける明示許可付き外部試験で確認し、ネットワーク失敗時のフォールバックも確認。CSP script-src は self のまま。実ブラウザ未実行。
