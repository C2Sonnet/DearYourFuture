<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Private proposal inbox and entry forms. A member never touches published business tables. */
final class ContributionsController {
    public static function isRoute(string $path):bool {
        return in_array($path,['/contributions','/contributions/new','/contributions/submit'],true)
            || preg_match('~\A/contributions/[a-f0-9]{32}(?:/withdraw)?\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        $user=Identity::current();
        if($user===null){self::problem('ログインが必要です',401);return;}
        $db=Database::connected();$userId=(int)$user['id'];
        if($path==='/contributions'){
            if($method!=='GET'){self::problem('許可されない操作です',405);return;}
            self::index($db,$userId);return;
        }
        if($path==='/contributions/new'){
            if($method!=='GET'){self::problem('許可されない操作です',405);return;}
            try{self::form($db);}catch(\InvalidArgumentException $e){self::problem($e->getMessage(),422);}return;
        }
        if($path==='/contributions/submit'){
            if($method!=='POST'){self::problem('POSTが必要です',405);return;}
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem('操作が認証されませんでした',403);return;}
            try{$id=ProposalRepository::submit($db,$userId,$_POST);header('Location: /contributions/'.$id.'?created=1',true,303);return;}
            catch(\InvalidArgumentException $e){self::problem($e->getMessage(),422);return;}
            catch(\RuntimeException $e){self::problem($e->getMessage(),409);return;}
        }
        if(preg_match('~\A/contributions/([a-f0-9]{32})(/withdraw)?\z~D',$path,$m)){
            $row=ProposalRepository::readMine($db,$m[1],$userId);
            if(!$row){self::problem('提案が見つかりません',404);return;}
            if(isset($m[2])){
                if($method!=='POST'){self::problem('POSTが必要です',405);return;}
                if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem('操作が認証されませんでした',403);return;}
                try{ProposalRepository::withdraw($db,$m[1],$userId);header('Location: /contributions?withdrawn=1',true,303);return;}
                catch(\RuntimeException $e){self::problem('取下げできない状態です',409);return;}
            }
            if($method!=='GET'){self::problem('許可されない操作です',405);return;}
            self::detail($row);return;
        }
        self::problem('ページがありません',404);
    }
    private static function index(PDO $db,int $userId):void {
        $page=self::page();$rows=ProposalRepository::listMine($db,$userId,$page);
        $h='<section class="hero compact"><span class="eyebrow">MY CONTRIBUTIONS</span><h1>自分の修正提案</h1><p>公開情報への変更は、出典と理由を添えて運営に提案できます。審査まではサイト内容を変更しません。</p></section>';
        $h.='<p><a class="btn primary" href="/contributions/new?type=companies&action=create">新しい企業を提案する</a></p>';
        if(isset($_GET['withdrawn']))$h.='<p role="status" class="note">提案を取り下げました。</p>';
        if(!$rows)$h.='<p class="note">提案はまだありません。企業詳細ページからも編集を提案できます。</p>';
        foreach($rows as $row){$h.='<article class="panel"><h2><a class="text-link" href="/contributions/'.$row['id'].'">'.Security::h(ProposalData::publicSummary($row['record_type'],$row['operation'])).'</a></h2><p>状態：'.Security::h(self::status($row['status'])).' / 投稿日：'.Security::h($row['created_at']).'</p><p>'.Security::h($row['reason']).'</p></article>';}
        $h.=self::paginate('/contributions',$page,count($rows));
        Kernel::layout('自分の修正提案',$h);
    }
    private static function form(PDO $db):void {
        $type=ProposalData::kind($_GET['type']??'companies');
        $action=ProposalData::action($_GET['action']??'create');
        $current=null;$companyId=null;$recordId=null;
        if($action!=='create'){
            $recordId=BusinessData::id(is_string($_GET['id']??null)?$_GET['id']:'');
            $current=BusinessRepository::read($db,$type,$recordId,true);
            if(!$current)throw new \InvalidArgumentException('公開レコードがありません');
            $companyId=$type==='companies'?$recordId:(string)$current['company_id'];
            if(!BusinessRepository::read($db,'companies',$companyId,true) || ($current['archived_at']??null)!==null)throw new \InvalidArgumentException('公開されていない情報です');
            if($type==='company_locations'&&$current['verification_state']==='private')throw new \InvalidArgumentException('非公開拠点です');
        }elseif($type!=='companies'){
            $companyId=BusinessData::id(is_string($_GET['company_id']??null)?$_GET['company_id']:'');
            if(!BusinessRepository::read($db,'companies',$companyId,true))throw new \InvalidArgumentException('公開企業を指定してください');
        }
        $names=['companies'=>'企業','brands'=>'ブランド','company_locations'=>'拠点','jobs'=>'求人'];
        $verbs=['create'=>'新規登録','update'=>'修正','archive'=>'削除（アーカイブ）'];
        $h='<section class="hero compact"><h1>'.Security::h($names[$type].'の'.$verbs[$action].'提案').'</h1><p>入力した内容は承認されるまで公開されません。根拠が不確かな情報を「確定」と書かないでください。</p></section>';
        $h.='<form method="post" action="/contributions/submit" class="setup-form">'.Session::csrfField().'<input type="hidden" name="record_type" value="'.$type.'"><input type="hidden" name="operation" value="'.$action.'">';
        if($recordId!==null)$h.='<input type="hidden" name="record_id" value="'.$recordId.'">';
        if($companyId!==null)$h.='<input type="hidden" name="company_id" value="'.$companyId.'">';
        if($action!=='archive')$h.=self::fields($db,$type,$current);
        $h.='<label>変更の理由・確認事項（必須）<textarea name="reason" rows="4" required maxlength="1500" placeholder="どの情報を、なぜ追加・修正したいですか？"></textarea></label>';
        $h.='<label>根拠URL（任意）<input type="url" name="evidence_url" maxlength="2000" placeholder="https://"></label>';
        $h.='<button type="submit" class="btn primary">審査へ提出する</button></form><p><a class="text-link" href="/contributions">自分の提案一覧へ</a></p>';
        Kernel::layout('情報変更の提案',$h);
    }
    private static function fields(PDO $db,string $type,?array $current):string {
        $configs=match($type){
            'companies'=>['display_name'=>['企業名','text'],'legal_name'=>['法人名','text'],'description'=>['概要','textarea'],'website_url'=>['公式URL','url'],'company_source_id'=>['公開出典ID','source']],
            'brands'=>['name'=>['ブランド名','text']],
            'company_locations'=>['name'=>['拠点名','text'],'kind'=>['拠点種別','location_kind'],'address'=>['所在地（未確認なら空欄）','text'],'source_id'=>['根拠資料','source'],'verified_on'=>['最終確認日','date'],'note'=>['注記','textarea']],
            'jobs'=>['title'=>['求人名','text'],'category'=>['職種','category'],'graduate_year'=>['卒業年','number'],'recruitment_state'=>['募集状況（確定は管理者が判断）','recruitment'],'source_id'=>['根拠資料','source'],'observed_on'=>['確認日','date'],'deadline_on'=>['締切','date'],'note'=>['注記','textarea']]
        };
        $h='';foreach($configs as $key=>[$label,$kind]){
            $value=$current[$key]??'';
            if($key==='company_source_id')$value='';
            if($kind==='source'){
                $h.='<label>'.Security::h($label).'<select name="'.$key.'"><option value="">指定なし</option>';
                foreach(BusinessRepository::list($db,'sources',true,100,0) as $source){$h.='<option value="'.$source['id'].'"'.($source['id']===$value?' selected':'').'>'.Security::h($source['title']).'</option>';}
                $h.='</select></label>';continue;
            }
            if($kind==='location_kind')$opts=BusinessData::LOCATION_KIND_LABELS;
            elseif($kind==='category')$opts=array_combine(['pastry','bread','research','product_development','quality_control','other'],['製菓','製パン','研究開発','商品開発','品質管理','その他']);
            elseif($kind==='recruitment')$opts=['unverified'=>'募集状態未確認','listed'=>'募集掲載あり・残枠未確認','closed'=>'募集受付終了'];
            else $opts=[];
            $h.='<label>'.Security::h($label);
            if($opts){$h.='<select name="'.$key.'">';foreach($opts as $v=>$txt)$h.='<option value="'.Security::h((string)$v).'"'.((string)$value===(string)$v?' selected':'').'>'.Security::h((string)$txt).'</option>';$h.='</select>';}
            elseif($kind==='textarea')$h.='<textarea name="'.$key.'" rows="3">'.Security::h((string)$value).'</textarea>';
            else $h.='<input type="'.($kind==='number'?'number':($kind==='date'?'date':($kind==='url'?'url':'text'))).'" name="'.$key.'" value="'.Security::h((string)($value?:($key==='graduate_year'?'2027':''))).'"'.(in_array($key,['display_name','name','title'],true)?' required':'').'>';
            $h.='</label>';
        }
        if($type==='company_locations')$h.='<input type="hidden" name="verification_state" value="candidate">';
        return $h;
    }
    private static function detail(array $row):void {
        $h='<section class="hero compact"><h1>提出した提案</h1><p>状態：'.Security::h(self::status($row['status'])).'</p></section>';
        if(isset($_GET['created']))$h.='<p role="status" class="note">提案を提出しました。内容はまだ公開されていません。</p>';
        $h.='<p>対象：'.Security::h(ProposalData::publicSummary($row['record_type'],$row['operation'])).'</p><p>理由：'.Security::h($row['reason']).'</p><p>根拠：';
        if($row['evidence_url'])$h.='<a href="'.Security::h($row['evidence_url']).'" target="_blank" rel="noopener noreferrer nofollow">参照URL</a>';
        else $h.='未指定';
        $h.='</p>';
        if($row['review_reason'])$h.='<p class="note">審査コメント：'.Security::h($row['review_reason']).'</p>';
        if($row['status']==='pending')$h.='<form method="post" class="setup-form" action="/contributions/'.$row['id'].'/withdraw">'.Session::csrfField().'<button type="submit" class="btn ghost">この提案を取り下げる</button></form>';
        $h.='<a class="text-link" href="/contributions">一覧へ戻る</a>';
        Kernel::layout('提案の状況',$h);
    }
    private static function page():int {$raw=$_GET['page']??'1';return is_string($raw)&&preg_match('/\A[1-9][0-9]{0,2}\z/D',$raw)?min((int)$raw,1000):1;}
    private static function paginate(string $url,int $page,int $total):string {return '<nav aria-label="ページ送り">'.($page>1?'<a href="'.$url.'?page='.($page-1).'">前へ</a> ':'').($total===25?'<a href="'.$url.'?page='.($page+1).'">次へ</a>':'').'</nav>';}
    private static function status(string $code):string {return ['pending'=>'審査中','approved'=>'承認済','rejected'=>'見送り','needs_revision'=>'追加確認が必要','withdrawn'=>'取り下げ済'][$code]??'確認中';}
    private static function problem(string $message,int $status):void {http_response_code($status);Kernel::layout('操作できません','<section class="hero compact"><h1>'.Security::h($message).'</h1></section>',false);}
}
