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

# c1.0.0-rc.1 — 追加要件・出典と公開境界

## 出典
- サイト開発 v3.0.0 正式リリースフォルダ：https://drive.google.com/drive/folders/1hwkdgxiyquVlKF2KdCJsqKqzqhlV03d1
- サイト開発 v3.0.0 リリースノート（実取得したMarkdown本文を app/data/v3.0.0-release-notes.md に収録）：https://docs.google.com/document/d/1R6n4hBxER7ufEE7_rgbwnEuI6DGS5td9/edit
- 開発資料画面の工程・日付・文書一覧は v2.6.1 公開用原本の app/Core/AdminDevelopmentController.php から取得。公開原本のSHA256：7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3。原画面は DEVELOPER 限定、PDFは resources/private/waterfall で保持。PDF本体は公開用ZIPにない。v3.0.0の画面実体は公開原本未取得につき未比較。
- v3.0.0 の公開用GitHub_Source ZIPを公開リポジトリフォルダ・正式リリースフォルダ・Drive検索で確認できず。Private_Deployment と作業アーカイブは取得しない。

## 開発資料
「コード」の隣に「開発資料」。工程日付、進捗100%（文書工程のみ）、4段階の流れ、8点の文書カード。固定図はレスポンシブHTML/CSSで再現し、今回はMermaidを新規導入しない。実装工程のPDF指定なし。既存の公開Markdown資料を索引化。PDFは承認済み原本内 `docs/development/{requirements,requirements-spec,basic-design,detailed-design,test-spec,test-report,operations,maintenance}.pdf` の8固定名のみ専用経路で配信。未収録ならリンクなし、リクエスト404、ハッシュ不一致503。実体のMIMEを確認して添付ダウンロードとする。PDFプレビュー実行はしない。公開専用PDFが別配布の場合、サイト開発側で公開版に組み込むか、別承認済み資料管理仕様を確定する必要がある。

## v3.0.0 追加登録
正式ノートはサイト開発側の発行済みノートと表示し、実コード登録待ちを明記。main、タグ、履歴、ダウンロードの実体は承認済みZIPの登録時に連動。登録後は登録済みノート表示に一本化する。Git commitのIDは実際の記録を取得した場合だけ設定し、未取得ならアーカイブSHA由来のIDを用いる。

## Zen Maru Gothic
指定のpreconnectとGoogle Fonts CSSリンクを追加。全体セレクタと既存フォント指定を変更し、コードも指定フォントを適用（等幅ではないため列揃えの見え方は変わり得る）。必要なHTTPSオリジンのみCSPに許可。script-src self、default-src none、画像 self、その他の制限維持。外部通信が発生するため、ネットワーク・実読込み・プライバシー通知は外部受入で確認。

## 判定
正式 c1.0.0 は未発行。内部コード追加と模擬試験は完了したが、実公開v3.0.0原本および公開PDFの登録・実機受入は未完了。コード公開サイトの既存v2.6.1原本は変更しない。Google Drive専用保存・Rewrite断片のみ。
