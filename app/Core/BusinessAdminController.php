<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Authenticated ADMIN+ business data editor; actions use POST, CSRF, revision locking and audit. */
final class BusinessAdminController {
    public static function isRoute(string $path):bool {
        return $path==='/admin/data'||$path==='/admin/data/assign-workplace'||preg_match('~\A/admin/data/(companies|brands|sources|company_locations|jobs)(?:/(new|[a-f0-9]{32}))?\z~D',$path)===1;
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!in_array((string)($user['role']??''),['ADMIN','FOUNDER','DEVELOPER'],true)){http_response_code(403);return;}
        $db=Database::connected();
        if($path==='/admin/data'){self::index();return;}
        if($path==='/admin/data/assign-workplace'){self::assignment($db,$method,$user);return;}
        if(!preg_match('~\A/admin/data/(companies|brands|sources|company_locations|jobs)(?:/(new|[a-f0-9]{32}))?\z~D',$path,$m)){self::problem('ページがありません',404);return;}
        $type=$m[1];$id=$m[2]??null;if($id==='new')$id=null;
        if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');self::problem('許可されない操作',405);return;}
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem('操作が認証されませんでした',403);return;}
            try {
                if($id===null){
                    $parent=is_string($_POST['company_id']??null)?$_POST['company_id']:null;
                    $new=BusinessRepository::create($db,$type,$_POST,(int)$user['id'],$parent);
                    header('Location: /admin/data/'.$type.'/'.$new.'?created=1',true,303);return;
                }
                $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
                if($revision===false||$revision===null||$revision<1)throw new \InvalidArgumentException('Revision required');
                if(($_POST['action']??'')==='archive'){
                    if(($_POST['confirmed']??'')!=='yes')throw new \InvalidArgumentException('Confirm archive operation');
                    BusinessRepository::archive($db,$type,$id,(int)$user['id'],$revision);
                    header('Location: /admin/data/'.$type.'?archived=1',true,303);return;
                }
                if(($_POST['action']??'')!=='save')throw new \InvalidArgumentException('Invalid action');
                BusinessRepository::update($db,$type,$id,$_POST,(int)$user['id'],$revision);
                header('Location: /admin/data/'.$type.'/'.$id.'?saved=1',true,303);return;
            }catch(\InvalidArgumentException $e){self::problem($e->getMessage(),422);return;}
            catch(\RuntimeException $e){self::problem($e->getMessage(),409);return;}
        }
        if($id!==null){$row=BusinessRepository::read($db,$type,$id);if(!$row){self::problem('レコードが見つかりません',404);return;}}
        else $row=null;
        self::editor($db,$type,$row);
    }
    private static function index():void {
        $html='<section class="hero compact"><span class="eyebrow">ADMIN · DATA</span><h1>企業情報の管理</h1><p>企業・求人・住所・出典を区別し、出典と履歴を残しながら編集します。</p></section><div class="link-grid">';
        foreach(['companies'=>'企業','brands'=>'ブランド','sources'=>'出典','company_locations'=>'拠点','jobs'=>'求人'] as $type=>$label)$html.='<a class="linked" href="/admin/data/'.$type.'">'.Security::h($label).' →</a>';
        $html.='<a class="linked" href="/admin/data/conflicts">原典の競合解消 →</a><a class="linked" href="/admin/data/history">変更履歴のエクスポート →</a><a class="linked" href="/admin/data/imports">履歴インポート →</a><a class="linked" href="/admin/data/reviewed-reimport">審査済み原典の再取込 →</a><a class="linked" href="/admin/data/research-identities">企業27件の確定フィード取込・ID対応プレビュー →</a><a class="linked" href="/admin/proposals">修正提案の審査 →</a><a class="linked" href="/activity">公開変更履歴 →</a><a class="linked" href="/admin/data/assign-workplace">求人と勤務候補地の紐付け →</a><a class="linked" href="/admin/library">企業資料ライブラリ管理 →</a></div><p class="note">未確認の勤務地には確定ピンを付けず、企業情報の削除は論理削除で扱います。</p>';
        Kernel::layout('企業データ管理',$html,false);
    }
    private static function editor(PDO $db,string $type,?array $current):void {
        $fields=self::specs($type);
        $pluralName=['companies'=>'企業','brands'=>'ブランド','sources'=>'出典','company_locations'=>'拠点','jobs'=>'求人'][$type];
        $html='<section class="hero compact"><span class="eyebrow">ADMIN · '.Security::h(strtoupper($type)).'</span><h1>'.Security::h($pluralName).'の管理</h1><p>更新競合を検出し、編集・アーカイブの履歴を監査へ記録します。</p></section>';
        if(!empty($_GET['created']))$html.='<p class="note" role="status">新規登録しました。</p>';
        if(!empty($_GET['saved']))$html.='<p class="note" role="status">更新しました。</p>';
        if($current===null){
            $page=isset($_GET['page'])&&is_string($_GET['page'])&&preg_match('/\A[1-9]\d{0,2}\z/',$_GET['page'])?min((int)$_GET['page'],500):1;
            $rows=BusinessRepository::list($db,$type,false,20,($page-1)*20);
            $html.='<div class="section"><h2>登録済み一覧</h2>';
            if(!$rows)$html.='<p>まだありません。</p>';
            foreach($rows as $r){$title=$r['display_name']??$r['title']??$r['name']??$r['id'];$html.='<p><a class="text-link" href="/admin/data/'.$type.'/'.$r['id'].'">'.Security::h((string)$title).'</a> <small>rev '.(int)$r['revision'].'</small></p>';}
            $html.='<p>';if($page>1)$html.='<a href="?page='.($page-1).'">前へ</a> ';if(count($rows)===20)$html.='<a href="?page='.($page+1).'">次へ</a>';$html.='</p></div><h2>新規登録</h2>';
        }else $html.='<p class="note">編集対象：'.Security::h($current['id']).' / revision '.(int)$current['revision'].'</p>';
        $action='/admin/data/'.$type.($current!==null?'/'.$current['id']:'');
        $html.='<form method="post" action="'.$action.'" class="setup-form">'.Session::csrfField();
        if($current!==null)$html.='<input type="hidden" name="revision" value="'.(int)$current['revision'].'"><input type="hidden" name="action" value="save">';
        if(isset(['brands'=>1,'company_locations'=>1,'jobs'=>1][$type])){
            if($current!==null)$html.='<p>所属会社ID：'.Security::h($current['company_id']).'（変更不可）</p>';
            else $html.=self::selectRows($db,'company_id','所属企業','companies',null,false);
        }
        foreach($fields as $key=>$schema){
            [$label,$kind,$options]=$schema;$value=$current[$key]??($kind==='select'?(string)array_key_first($options):'');
            if($key==='company_source_id'){$html.=self::selectRows($db,$key,$label,'sources',null,true);continue;}
            if($key==='source_id'){$html.=self::selectRows($db,$key,$label,'sources',(string)($value??''),true);continue;}
            $html.='<label>'.Security::h($label);
            if($kind==='select'){
                $html.='<select name="'.Security::h($key).'">';foreach($options as $k=>$v)$html.='<option value="'.Security::h((string)$k).'"'.((string)$value===(string)$k?' selected':'').'>'.Security::h((string)$v).'</option>';$html.='</select>';
            }elseif($kind==='textarea')$html.='<textarea name="'.Security::h($key).'" rows="3">'.Security::h((string)$value).'</textarea>';
            else {$typeHtml=$kind==='date'?'date':($kind==='url'?'url':'text');$html.='<input type="'.$typeHtml.'" name="'.Security::h($key).'" value="'.Security::h((string)$value).'"'.(in_array($key,['display_name','name','title'],true)?' required':'').'>';}
            $html.='</label>';
        }
        $html.='<button class="btn primary" type="submit">'.($current===null?'登録（出典確認前は下書き）':'変更を保存').'</button></form>';
        if($current!==null)$html.='<form method="post" action="'.$action.'" class="setup-form">'.Session::csrfField().'<input type="hidden" name="revision" value="'.(int)$current['revision'].'"><input type="hidden" name="action" value="archive"><label><input type="checkbox" name="confirmed" value="yes" required> アーカイブ操作を確認</label><button type="submit" class="btn ghost">論理削除・非公開にする</button></form>';
        $html.='<a class="text-link" href="/admin/data">管理トップへ戻る</a>';
        Kernel::layout($pluralName.'管理',$html,false);
    }
    private static function selectRows(PDO $db,string $name,string $label,string $table,?string $selected,bool $optional):string {
        $rows=BusinessRepository::list($db,$table,false,100,0);
        $html='<label>'.Security::h($label).'<select name="'.Security::h($name).'"'.($optional?'':' required').'>';
        if($optional)$html.='<option value="">指定なし</option>';
        foreach($rows as $r){
            if($table==='sources' && $r['archived_at']!==null)continue;
            if($table==='companies' && $r['visibility']==='archived')continue;
            $txt=$r['display_name']??$r['title']??$r['name']??$r['id'];
            $html.='<option value="'.$r['id'].'"'.($selected===$r['id']?' selected':'').'>'.Security::h((string)$txt).'</option>';
        }
        return $html.'</select></label>';
    }
    private static function specs(string $type):array {
        return match($type){
            'companies'=>[
                'display_name'=>['企業表示名','text',[]],'legal_name'=>['法人名','text',[]],
                'description'=>['会社概要（募集確約を含めない）','textarea',[]],
                'website_url'=>['公式サイト等のURL','url',[]],
                'visibility'=>['掲載状態','select',['draft'=>'下書き','published'=>'公開（出典登録が必須）','archived'=>'アーカイブ']],
                'company_source_id'=>['根拠資料（公開時必須）','source',[]],
            ],
            'brands'=>['name'=>['ブランド名','text',[]]],
            'sources'=>[
                'title'=>['資料名','text',[]],'source_url'=>['出典URL','url',[]],
                'source_type'=>['資料分類','select',array_combine(BusinessData::SOURCE_TYPES,array_map([BusinessData::class,'sourceTypeLabel'],BusinessData::SOURCE_TYPES))],
                'publication_state'=>['資料公開範囲','select',['private'=>'管理者専用','public'=>'一般公開']],
                'observed_on'=>['確認日','date',[]],'note'=>['備考','textarea',[]],
            ],
            'company_locations'=>[
                'name'=>['拠点名','text',[]],
                'kind'=>['拠点種類','select',BusinessData::LOCATION_KIND_LABELS],
                'address'=>['住所','text',[]],
                'verification_state'=>['所在地確認状況','select',BusinessData::VERIFICATION_LABELS],
                'source_id'=>['根拠資料','source',[]],
                'latitude'=>['緯度（確認済みのみ）','text',[]], 'longitude'=>['経度（確認済みのみ）','text',[]],
                'verified_on'=>['確認日','date',[]], 'note'=>['拠点に関する注意','textarea',[]],
            ],
            'jobs'=>[
                'title'=>['募集職種／コース','text',[]],
                'category'=>['職種分類','select',['pastry'=>'製菓','bread'=>'製パン','research'=>'研究','product_development'=>'商品開発','quality_control'=>'品質管理','other'=>'その他']],
                'graduate_year'=>['卒業年度','text',[]],
                'recruitment_state'=>['募集確認状況','select',BusinessData::RECRUITMENT_LABELS],
                'source_id'=>['根拠資料','source',[]], 'observed_on'=>['確認日','date',[]],
                'deadline_on'=>['資料掲載の締切日（最新要確認）','date',[]], 'note'=>['求人メモ','textarea',[]],
            ],
        };
    }
    private static function assignment(PDO $db,string $method,array $user):void {
        if($method==='POST'){
            if(!Session::validCsrf((string)($_POST['csrf']??''))){self::problem('操作が認証されませんでした',403);return;}
            try{
                BusinessRepository::assignJobLocation($db,(string)($_POST['job_id']??''),(string)($_POST['location_id']??''),(string)($_POST['assignment_state']??''),(int)$user['id'],($_POST['action']??'')==='unlink');
                header('Location: /admin/data/assign-workplace?saved=1',true,303);return;
            }catch(\InvalidArgumentException $e){self::problem($e->getMessage(),422);return;}
        }
        if($method!=='GET'){header('Allow: GET, POST');self::problem('許可されない操作',405);return;}
        $jobs=BusinessRepository::list($db,'jobs',false,100,0);$locs=BusinessRepository::list($db,'company_locations',false,100,0);
        $html='<section class="hero compact"><h1>求人と勤務地候補の紐付け</h1><p>企業と同じ拠点だけを紐付けできます。確認済み拠点以外は確定にできません。</p></section>';
        if(!empty($_GET['saved']))$html.='<p class="note">紐付けを更新しました。</p>';
        $html.='<form method="post" class="setup-form">'.Session::csrfField().'<label>求人<select name="job_id">';
        foreach($jobs as $r)$html.='<option value="'.$r['id'].'">'.Security::h($r['title']).'（'.Security::h($r['company_id']).'）</option>';
        $html.='</select></label><label>勤務先候補<select name="location_id">';
        foreach($locs as $r)$html.='<option value="'.$r['id'].'">'.Security::h($r['name']).'（'.Security::h($r['company_id']).'）</option>';
        $html.='</select></label><label>状態<select name="assignment_state"><option value="candidate">候補・未確定</option><option value="confirmed">確定済み</option></select></label><label>操作<select name="action"><option value="link">紐付け</option><option value="unlink">解除</option></select></label><button class="btn primary">反映する</button></form><a href="/admin/data">管理トップへ</a>';
        Kernel::layout('求人勤務地の紐付け',$html,false);
    }
    private static function problem(string $message,int $status):void {http_response_code($status);Kernel::layout('データ操作を確認','<section class="hero compact"><h1>確認が必要です</h1><p>'.Security::h($message).'</p><a href="/admin/data">管理トップへ戻る</a></section>',false);}
}
