# v0.11.0 20機能群再監査
2026-11-24。コードの静的確認と今回・前版の試験証跡を区別。実ブラウザ受入は全機能未実施。

| ID | 機能 | 実装根拠 | 留意点 |
|---|---|---|---|
| F01 | Repoトップ | public/index.php code | HTTP構造は前版確認 |
| F02 | フォルダ階層 | code dir・パンくず | 模擬HTTP |
| F03 | コード・行番号・コピー | file、site.js | コピー実機未試験 |
| F04 | Go to file | toolbar、file-results | 模擬DOM |
| F05 | README Markdown | app/Markdown.php | GFM完全互換ではない |
| F06 | 目次検索 | Markdown、site.js | 模擬DOM・表示試験 |
| F07 | License/Security | doc tabs | HTTP構造は前版確認 |
| F08 | About/topics/URL | config/site.php、aside | 静的確認 |
| F09 | 作者情報ポップアップ | profile details | 画像許諾HOLD |
| F10 | 言語比 | languageView | GitHub Linguistとは異なる |
| F11 | ブランチ検索/分類 | branches、main | 単一mainのみ |
| F12 | コミット一覧/概要 | commits、commitPopover | スナップショット履歴 |
| F13 | コミット差分 | CompareView.php | 実v2.0.0親ZIP未取得 |
| F14 | 差分検索 | CompareView.php | 模擬3版HTTP合格 |
| F15 | Tags | tags | 収録版のみ |
| F16 | Releases | releases | 収録版のみ |
| F17 | Compare | CompareView.php、publish.py | 未生成比較はCLI再生成 |
| F18 | ZIP/tar.gz | download、Repository | ハッシュ検証・旧形式制約 |
| F19 | Light/Dark/System | theme-init.js、site.js | 模擬DOM合格、実ブラウザHOLD |
| F20 | Back to top | floating、site.js | 模擬DOM合格、実ブラウザHOLD |

20群の対応コードは存在するが、実データ・実機で全機能合格とはしない。社交機能、ログイン、実Git操作は対象外。

## v0.15.0 再監査
F09 作者プロフィールは日本語名・@C2Sonnet・公式サイト・ユーザー提供アバターを表示。F01 ヘッダー・見出し・タブアイコンはサイト開発原本favicon。全20機能の固定UIは日本語化し、原本内容を翻訳・変更しない。F09実ブラウザと素材権利最終判断はHOLD。

v0.16.0: F03にローカルHighlight.js（対応する拡張子・明示言語のみ、長大コードは平文）、F07にドキュメントアンカー、F11にポップオーバー座標調整を追加。F19を初期darkとlight/dark二択に改定。実ブラウザ再受入HOLD。

## 追加機能 F21/F22（c1.0.0-rc.1）
- F21 開発資料：独立ナビ、固定工程図、8文書一覧、公開済みMarkdown資料、承認済みPDFのハッシュ検証付き取得。未収録PDFは非リンク。
- F22 独立c版番号・全体フォント：ヘッダー、フッター、配置検査、配布前検査のc番号対応。Zen Maru Gothicを全体に適用。Google Fontsの読み込みに必要なCSPのみ追加。
- v3.0.0の実ソースが未取得なのでタグ・コミットを生成しない。正式ノートは登録待ちの明示付きで別表示。
