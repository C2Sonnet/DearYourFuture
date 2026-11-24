<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Authenticated or guest-scoped home summary: published sources only. */
final class HomeController {
    public static function summary(PDO $db):array {
        $count=(int)$db->query("SELECT COUNT(*) FROM companies WHERE visibility='published'")->fetchColumn();
        $stmt=$db->prepare("SELECT j.id,j.title,j.deadline_on,j.observed_on,c.display_name,c.id AS company_id FROM jobs j JOIN companies c ON c.id=j.company_id WHERE c.visibility='published' AND j.archived_at IS NULL AND j.deadline_on >= UTC_DATE() AND j.recruitment_state IN ('listed','confirmed_open') ORDER BY j.deadline_on ASC LIMIT 5");
        $stmt->execute();$deadlines=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt=$db->prepare("SELECT e.record_type,e.record_id,e.operation,e.created_at FROM business_change_events e WHERE e.record_type='companies' AND EXISTS (SELECT 1 FROM companies c WHERE c.id=e.record_id AND c.visibility='published') ORDER BY e.created_at DESC LIMIT 5");
        $stmt->execute();$recent=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['count'=>$count,'deadlines'=>$deadlines,'recent'=>$recent];
    }
    public static function render(array $info,bool $showGuide=true):string {
        $html='<section class="hero"><span class="eyebrow">DEAR YOUR FUTURE</span><img class="hero-cat" src="/assets/cat.svg" alt="見守る猫のイラスト"><h1>キミの未来へ、<br>小さな一歩から。</h1><p>根拠を確認して企業を探し、自分に合う働き方を比較しましょう。</p>'.($showGuide?'<a class="btn primary" href="/guide">はじめての方はこちら</a>':'').'</section>';
        $html.='<section class="section"><h2>横断検索</h2>'.BusinessSearch::form('companies',[]).'</section>';
        return $html;
    }
    public static function handle(string $method):void {
        if($method==='POST'){
            $user=Identity::current();
            if($user===null){http_response_code(401);Kernel::layout('ログインしてください','<p>ログイン後に設定を変更できます。</p>');return;}
            $csrf=$_POST['csrf']??null;
            if(!is_string($csrf)||!Session::validCsrf($csrf)){http_response_code(403);Kernel::layout('確認が必要です','<p>再読み込みしてから操作してください。</p>');return;}
            $action=$_POST['recommend_action']??null;
            if(!in_array($action,['save','reset'],true)){http_response_code(400);Kernel::layout('設定エラー','<p>無効な操作です。</p>');return;}
            $raw=$_POST['criteria']??[];
            if(!is_array($raw)){http_response_code(400);Kernel::layout('設定エラー','<p>選択項目が正しくありません。</p>');return;}
            try{ResearchRecommendation::persist(Database::connected(),(int)$user['id'],$raw,$action==='reset');}
            catch(\InvalidArgumentException){http_response_code(400);Kernel::layout('設定エラー','<p>選択項目を確認してください。</p>');return;}
            header('Location: /#recommend-conditions',true,303);return;
        }
        if($method!=='GET'){http_response_code(405);header('Allow: GET');Kernel::layout('操作できません','<p>許可されない操作です。</p>');return;}
        $db=Database::connected();$user=Identity::current();
        $done=$user!==null?WorkspaceRepository::guideSteps($db,(int)$user['id']):[];
        $complete=$user!==null&&count(array_intersect($done,WorkspaceRepository::GUIDE_STEPS))===count(WorkspaceRepository::GUIDE_STEPS);
        $html=self::render(self::summary($db),!$complete);
        if($user!==null)$html.=ViewHistory::gallery($db,(int)$user['id']);
        if($user!==null){$panel=ResearchRecommendation::render($db,(int)$user['id']);
            $needle='<section class="section"><h2>横断検索</h2>';
            $html=str_replace($needle,$panel.$needle,$html);}
        if($user!==null){
            if(count(array_intersect($done,WorkspaceRepository::GUIDE_STEPS))<count(WorkspaceRepository::GUIDE_STEPS)){
                $counts=ApplicationRepository::statusCounts($db,(int)$user['id']);
                $recommendation=array_sum(array_map('intval',$counts))===0?'まずは気になる企業を1社見つけましょう。':'次の予定を確認し、必要に応じて選考の記録を残しましょう。';
                $html='<section class="onboarding-banner" role="status"><strong>はじめての方へ・クイックスタート</strong><p>'.Security::h($recommendation).'</p><p>ガイド達成：'.count(array_intersect($done,WorkspaceRepository::GUIDE_STEPS)).' / '.count(WorkspaceRepository::GUIDE_STEPS).'。後から再開できます。</p><a href="/guide" class="btn primary">ガイドへ</a></section>'.$html;
            }
        }
        Kernel::layout('ホーム',$html);
    }
}
