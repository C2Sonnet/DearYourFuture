<?php
declare(strict_types=1);
namespace KMM\Core;
final class Pages {
    public const NAV = [
        ['url'=>'/','label'=>'ホーム','icon'=>'⌂'],
        ['url'=>'/companies','label'=>'企業を探す','icon'=>'▤'],
        ['url'=>'/map','label'=>'企業マップ','icon'=>'⌖'],
        ['url'=>'/jobs','label'=>'求人を探す','icon'=>'▣'],
        ['url'=>'/library','label'=>'資料ライブラリ','icon'=>'▧'],
        ['url'=>'/applications','label'=>'就活管理','icon'=>'◷'],
        ['url'=>'/my','label'=>'マイリスト','icon'=>'♡'],
        ['url'=>'/activity','label'=>'変更履歴','icon'=>'↺'],
        ['url'=>'/guide','label'=>'ガイド','icon'=>'✧'],
    ];
    public const NAV_GROUPS = [
        ['label'=>'ホーム','url'=>'/','icon'=>'⌂'],
        ['label'=>'企業情報','items'=>[['label'=>'企業を探す','url'=>'/companies'],['label'=>'企業マップ','url'=>'/map'],['label'=>'資料ライブラリ','url'=>'/library']]],
        ['label'=>'求人情報','items'=>[['label'=>'求人を探す','url'=>'/jobs'],['label'=>'求人時間軸','url'=>'/jobs/timeline']]],
        ['label'=>'選考管理','items'=>[['label'=>'就活進展','url'=>'/applications'],['label'=>'マイリスト','url'=>'/my']]],
        ['label'=>'その他','items'=>[['label'=>'変更履歴','url'=>'/activity'],['label'=>'ガイド','url'=>'/guide'],['label'=>'サイト資源','url'=>'/site-resources'],['label'=>'データ集計','url'=>'/admin/data/statistics','admin_only'=>true],['label'=>'開発資料','url'=>'/admin/development','admin_only'=>true]]],
    ];
    public const SECTIONS = [
        '/companies'=>['title'=>'企業を探す','intro'=>'場所や仕事の内容、資料を比べながら、気になる企業を見つけましょう。','links'=>[['/companies/compare','企業比較'],['/applications/timeline','全企業の時間軸'],['/map','企業の位置を見る']]],
        '/map'=>['title'=>'企業マップ','intro'=>'確認済みの企業拠点のピンと、出典を閲覧します。未設置プレビューでは地図を表示できません。','links'=>[['/companies','企業一覧へ']]],
        '/jobs'=>['title'=>'求人を探す','intro'=>'募集内容や職種の適合性を一つずつ確認。掲載中でも応募受付済みとは限りません。','links'=>[['/companies','企業を探す'],['/applications/calendar','予定と締切']]],
        '/library'=>['title'=>'企業資料ライブラリ','intro'=>'企業資料は出典付きで、公開可能なものだけ閲覧できます。未設置プレビューでは一覧を表示できません。','links'=>[['/library/sources','資料の出典'],['/companies','企業から探す']]],
        '/applications'=>['title'=>'就活進展','intro'=>'「検討」「エントリー済」「選考中」「選考終了」「合格」を自分専用に管理します。','links'=>[['/applications/timeline','すべての企業の時間軸'],['/applications/calendar','応募・説明会カレンダー']]],
        '/my'=>['title'=>'マイリスト','intro'=>'保存した企業と個人用のメモはログイン後に、本人だけが閲覧できます。','links'=>[['/account/center','ユーザーセンター'],['/companies','企業検索']]],
        '/activity'=>['title'=>'変更履歴','intro'=>'誰が、いつ、どの情報を更新したか。公開可能な履歴と個人情報を分けて管理します。','links'=>[['/contributions','自分の修正提案']]],
        '/guide'=>['title'=>'はじめての方へ','intro'=>'「企業を見つける → 比べる → 検討リストへ → 応募 → 次の予定を確認」の順番から始めましょう。','links'=>[['/companies','企業を探してみる'],['/applications/timeline','時間軸の見方']]],
        '/account/center'=>['title'=>'ユーザーセンター','intro'=>'選考状態ごとの企業リストと、プロフィール・セキュリティ設定の入口です。ログインした本人の記録のみを表示します。','links'=>[['/account/profile','アバター・あだ名・ID・メール'],['/account/security','パスワード・安全設定']]],
        '/account/profile'=>['title'=>'プロフィール','intro'=>'アバター、表示名、公開用ID、メールを安全に変更できる機能を準備しています。','links'=>[['/account/security','パスワード設定']]],
        '/account/security'=>['title'=>'パスワードとセキュリティ','intro'=>'旧パスワードの再入力は不要とし、代替の本人確認を行う計画です。','links'=>[['/account/center','ユーザーセンター']]],
        '/applications/timeline'=>['title'=>'全企業タイムライン','intro'=>'検討中・エントリー・面接・結果まで、各応募案件の予定と履歴を一つの時間軸にまとめます。','links'=>[['/applications/calendar','カレンダー'],['/applications','応募管理']]],
        '/applications/calendar'=>['title'=>'応募カレンダー','intro'=>'締切・説明会・面接などの予定を、本人用の非公開データとして表示します。','links'=>[['/applications/timeline','時間軸を表示']]],
        '/library/sources'=>['title'=>'出典管理','intro'=>'公式・求人媒体・SNS・匿名口コミを分け、発行日と確認日を記録します。','links'=>[['/library','資料ライブラリ']]],
        '/contributions'=>['title'=>'自分の修正提案','intro'=>'企業情報の追加・補充・修正・削除提案の進捗を確認できます。','links'=>[['/activity','変更履歴']]],
        '/companies/compare'=>['title'=>'企業比較','intro'=>'企業と求人の待遇や勤務地、職種適合性を並べて比較する予定です。','links'=>[['/companies','企業一覧に戻る']]],
    ];
}
