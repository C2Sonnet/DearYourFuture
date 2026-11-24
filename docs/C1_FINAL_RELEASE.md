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
