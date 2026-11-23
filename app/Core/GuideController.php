<?php
declare(strict_types=1);
namespace KMM\Core;

/** Actionable first-use guide; does not claim browser-local or unsaved state is a server record. */
final class GuideController {
    public static function isRoute(string $path):bool{return in_array($path,['/guide','/guide/steps'],true);}
    public static function handle(string $path,string $method):void {
        $account=Identity::current();
        if($path==='/guide/steps'){
            if($account===null){header('Location: /account/login?next=%2Fguide',true,302);return;}
            if($method!=='POST'){http_response_code(405);header('Allow: POST');Kernel::layout('許可されない操作','<p>ガイドから操作してください。</p>');return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))){http_response_code(403);Kernel::layout('確認が必要です','<p>CSRF確認に失敗しました。</p>');return;}
            if(!Throttle::allow('guide-write:'.$account['id'],25,900)){http_response_code(429);Kernel::layout('操作制限','<p>時間を置いてください。</p>');return;}
            try {WorkspaceRepository::setGuideStep(Database::connected(),(int)$account['id'],(string)($_POST['step']??''),($_POST['action']??'')==='complete');}
            catch(\InvalidArgumentException){http_response_code(422);Kernel::layout('入力をご確認ください','<p>不正なステップです。</p>');return;}
            header('Location: /guide',true,303);return;
        }
        if($method!=='GET'){http_response_code(405);header('Allow: GET');Kernel::layout('はじめての方へ','<p>このページは閲覧専用です。</p>');return;}
        $counts=null;$completed=[];
        if($account!==null){$db=Database::connected();$counts=ApplicationRepository::statusCounts($db,(int)$account['id']);$completed=WorkspaceRepository::guideSteps($db,(int)$account['id']);}
        Kernel::layout('はじめての方へ',self::render($account,$counts,$completed));
    }
    public static function render(?array $account,?array $counts=null,array $completed=[]):string {
        $h='<section class="hero compact"><span class="eyebrow">WELCOME GUIDE</span><h1>はじめての方へ</h1><p>情報を調べるところから、選考の記録まで。必要な機能を順番に使えます。</p></section>';
        $steps=[
            ['ユーザーセンター','プロフィールやメール通知を管理します。','/account/center','ユーザーセンターへ'],
            ['企業を探す','企業情報・所在地・口コミを探します。','/companies','企業を探す'],
            ['求人を探す','給与や雇用形態、選考フローを確認します。','/jobs','求人を探す'],
            ['求人時間軸','企業横断の求人募集・締切日を月別で確認します。','/jobs/timeline','求人時間軸へ'],
            ['応募カレンダー','応募・面接・締切の予定を整理します。','/applications/calendar','カレンダーへ'],
            ['企業マップ','収録済み企業の拠点を地図と住所一覧で確認します。','/map','全企業の拠点マップへ'],
            ['企業資料','企業ごとの原典や参考資料を確認します。','/library','企業資料ライブラリへ'],
            ['就活管理','自分だけの応募・選考状況を管理します。','/applications','就活管理へ'],
            ['マイリスト','お気に入り・検討中の企業を整理します。','/my','マイリストへ'],
            ['メッセージ','届いたメッセージと通知を確認します。','/messages','メッセージへ'],
        ];
        if($account===null)$h.='<p class="note">閲覧のみ許可される設定では公開企業・資料を閲覧できます。マイリスト、応募、個人メッセージはログインが必要です。 <a href="/account/login?next=%2Fguide">ログインする</a></p>';
        else {
            $name=Security::h((string)($account['display_name']??'ユーザー'));
            $h.='<p role="status" class="note">'.$name.'さんのガイドです。個人の選考情報は本人以外に表示されません。</p>';
            if($counts!==null){$total=array_sum(array_map('intval',$counts));$h.='<p class="guide-count">登録済みの応募案件：<strong>'.(int)$total.'件</strong>（会社単位と求人単位を別件として計上）</p>';}
        }
        if($account!==null){
            $doneCount=count(array_intersect($completed,WorkspaceRepository::GUIDE_STEPS));
            $h.='<div class="guide-progress" role="group" aria-label="ガイドの完了状況"><label for="guide-progress-meter">ガイド達成度：'.$doneCount.' / '.count($steps).'（'.(int)round($doneCount/count($steps)*100).'％）</label><progress id="guide-progress-meter" value="'.$doneCount.'" max="'.count($steps).'">'.$doneCount.' / '.count($steps).'</progress></div>';
            $next=null;
            foreach(WorkspaceRepository::GUIDE_STEPS as $key){if(!in_array($key,$completed,true)){$next=$key;break;}}
            $h.=$next!==null?'<p><a class="btn primary" href="#guide-step-'.Security::h($next).'">未完了のステップから再開する</a></p>'
                             :'<p role="status" class="note">全ステップ完了です。必要になったらいつでも見直せます。</p>';
        }
        $h.='<ol class="guide-steps">';
        foreach($steps as $index=>[$label,$description,$href,$action]){
            $stepKey=WorkspaceRepository::GUIDE_STEPS[$index];$done=in_array($stepKey,$completed,true);
            $private=true;
            $h.='<li class="panel" id="guide-step-'.Security::h($stepKey).'"><div class="eyebrow">STEP '.str_pad((string)($index+1),2,'0',STR_PAD_LEFT).($done?' ／ 完了':'').'</div><h2>'.Security::h($label).'</h2><p>'.Security::h($description).'</p>';
            if($account===null&&$private)$h.='<span class="private-label">ログイン後に利用できます</span> <a class="btn ghost" href="/account/login?next='.rawurlencode($href).'">ログインして使う</a>';
            else $h.='<a class="btn ghost" href="'.Security::h($href).'">'.Security::h($action).'</a>';
            if($account!==null){$h.='<form method="post" action="/guide/steps" class="guide-step-form">'.Session::csrfField().'<input type="hidden" name="step" value="'.Security::h($stepKey).'"><input type="hidden" name="action" value="'.($done?'reset':'complete').'"><button type="submit" class="btn guide-state-btn '.($done?'is-complete':'is-incomplete').'">'.($done?'完了（未完成に戻す）':'未完成（完了にする）').'</button></form>';}

            $h.='</li>';
        }
        $h.='</ol><section class="section" id="mini-tour"><h2>クイックツアー</h2><p>以下のカードから、必要な操作へ直接進めます。ツアーを見ただけで応募・公開・送信は実行されません。</p><div class="cards">';
        foreach([['企業を絞り込む','/companies','職種と地域を選び、根拠や確認日を見ます。'],['比較リストを使う','/companies/compare','最大10社を比べ、未確認の条件は確認事項に残します。'],['個人メモを作る','/my','ログイン後、本人専用のお気に入り・メモを管理します。'],['予定を記録する','/applications/calendar','面接や応募の予定を残し、通知は設定から任意にONにできます。']] as [$title,$url,$desc])$h.='<a class="panel" href="'.Security::h($url).'"><h3>'.Security::h($title).'</h3><p>'.Security::h($desc).'</p></a>';
        $h.='</div></section><section class="section" id="faq"><h2>よくある質問</h2>';
        foreach([
            ['募集が掲載されていれば応募できますか？','いいえ。求人票の存在と、現在の受付継続・採用枠の残存は異なります。企業へ直接確認してください。'],
            ['本社が東京都なら東京都勤務ですか？','いいえ。拠点の住所と求人に記載された配属勤務地を分けて確認してください。'],
            ['留学生なら誰でも応募できますか？','応募資格、既卒・職歴条件、在留資格の適合は募集要項と担当窓口で個別に確認してください。'],
            ['お気に入りや応募記録は他人に見えますか？','個人メモ・応募・選考記録は原則本人専用です。管理業務と公開情報は別の権限で扱います。'],
            ['メッセージを削除すると相手の画面からも消えますか？','いいえ。受信者側と送信者側のゴミ箱は独立しています。'],
        ] as [$question,$answer])$h.='<details class="panel"><summary>'.Security::h($question).'</summary><p>'.Security::h($answer).'</p></details>';
        $h.='</section><section class="section" id="glossary"><h2>用語辞典</h2><dl>';
        foreach([
            '調査原典データ'=>'調査資料から取り込む原典データ（内部識別子：PROJECT_SEED）。正式公開前に出典と変更競合を確認します。',
            'ユーザー編集データ'=>'ユーザーによる承認済み編集（内部識別子：USER_OVERLAY）。原典を更新しても一方的に上書きしません。',
            '掲載中'=>'求人ページが存在する状態。現在募集中という保証ではありません。',
            '受付確認済み'=>'情報確認日当時の受付を示す状態。今日の残枠は改めて確認が必要です。',
            '勤務地候補'=>'募集要項で候補に挙がった配属先。確定配属と区別します。',
            '出典の信頼度'=>'公式資料、求人媒体、口コミ等の性質を分けた表示であり、内容の真実性の絶対保証ではありません。',
        ] as $term=>$def)$h.='<dt><strong>'.Security::h($term).'</strong></dt><dd>'.Security::h($def).'</dd>';
        $h.='</dl></section><p class="note">ガイド進捗は自分で操作した場合だけ保存されます。各機能を開いたことだけで応募・予約・公開情報の更新は行われません。</p>';
        return $h;
    }
}
