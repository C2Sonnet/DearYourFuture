<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Personal recently viewed records. Reuses the Schema41 user_saved_filters LONGTEXT, never a public cookie. */
final class ViewHistory {
    public const LABEL='__kmm_recent_views_v1__';
    public const MAX_PER_KIND=50;
    private static function valid(string $kind,string $id):bool {
        return in_array($kind,['companies','jobs'],true) && WorkspaceRepository::validFavoriteId($kind,$id);
    }
    public static function relative(?string $utc):string {
        if($utc===null||$utc==='')return '閲覧日時不明';
        try{$stamp=new \DateTimeImmutable($utc,new \DateTimeZone('UTC'));}catch(\Throwable){return '閲覧日時不明';}
        $secs=max(0,time()-$stamp->getTimestamp());
        if($secs<60)return 'たった今';
        if($secs<3600)return intdiv($secs,60).'分前';
        if($secs<86400)return intdiv($secs,3600).'時間前';
        if($secs<86400*30)return intdiv($secs,86400).'日前';
        if($secs<86400*365)return intdiv($secs,86400*30).'か月前';
        return intdiv($secs,86400*365).'年前';
    }
    public static function read(PDO $db,int $uid,bool $locked=false):array {
        if($uid<1)throw new \InvalidArgumentException('Account required');
        $q=$db->prepare('SELECT filters_json FROM user_saved_filters WHERE user_id=? AND label=? ORDER BY created_at DESC LIMIT 1'.($locked?' FOR UPDATE':''));
        $q->execute([$uid,self::LABEL]);$raw=$q->fetchColumn();$result=['companies'=>[],'jobs'=>[]];
        if(!is_string($raw)||strlen($raw)>450000)return $result;
        try{$data=json_decode($raw,true,25,JSON_THROW_ON_ERROR);}catch(\Throwable){return $result;}
        if(!is_array($data))return $result;
        foreach(['companies','jobs'] as $kind){
            if(!is_array($data[$kind]??null))continue;
            foreach(array_slice($data[$kind],0,80,true) as $id=>$entry){
                if(!is_string($id)||!self::valid($kind,$id)||!is_array($entry))continue;
                if(!is_string($entry['at']??null)||!is_string($entry['hash']??null))continue;
                $result[$kind][$id]=$entry;
            }
        }
        return $result;
    }
    private static function save(PDO $db,int $uid,array $data):void {
        foreach(['companies','jobs'] as $kind){
            uasort($data[$kind],static fn($a,$b)=>strcmp((string)($b['at']??''),(string)($a['at']??'')));
            $data[$kind]=array_slice($data[$kind],0,self::MAX_PER_KIND,true);
        }
        $db->prepare('DELETE FROM user_saved_filters WHERE user_id=? AND label=?')->execute([$uid,self::LABEL]);
        $db->prepare('INSERT INTO user_saved_filters(id,user_id,label,filters_json) VALUES (?,?,?,?)')
            ->execute([BusinessData::id(),$uid,self::LABEL,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)]);
    }
    /** Snapshot is both a content fingerprint and concise display facts. Do not read other users' private data. */
    public static function item(PDO $db,string $kind,string $id):?array {
        if(!self::valid($kind,$id))return null;
        $source=($kind==='companies'?ResearchR07::isCompanyId($id):ResearchR07::isJobId($id))?$id:
            ResearchR07::sourceId($db,$kind,$id);
        $r=$source!==null?($kind==='companies'?ResearchR07::company($source):ResearchR07::job($source)):null;
        $row=null;
        if(preg_match('/\A[a-f0-9]{32}\z/D',$id)===1){$row=BusinessRepository::read($db,$kind,$id,false);}
        elseif($source!==null){$dbId=ResearchR07::dbId($db,$kind,$source);if($dbId!==null)$row=BusinessRepository::read($db,$kind,$dbId,false);}
        if($r===null&&$row===null)return null;
        $name=$kind==='companies'?(is_array($r)?ResearchR07::displayName($r):(string)($row['display_name']??'企業名不明')):
            (is_array($r)?ResearchR07::jobTitle($r):(string)($row['title']??'求人名不明'));
        $url=$source!==null?($kind==='companies'?ResearchR07::companyUrl($db,$source):ResearchR07::jobUrl($db,$source)):'/'.$kind.'/'.$id;
        $facts=[];$compare=[];
        if($kind==='companies'){
            if($r!==null){
                $area=ResearchR07::localArea($r)??'所在地未確認';
                $facts[]='所在地：'.$area;
                $station=null;$walk=null;$hub=null;$rail=null;$employment=[];
                foreach(ResearchR07::companyJobs((string)($r['company_id']??'')) as $j){
                    $station??=ResearchR07::firstStation($j);
                    $comm=$j['commute']??[];
                    if(is_array($comm)){
                        if($walk===null&&is_numeric($comm['walk_minutes']??null))$walk=(int)$comm['walk_minutes'];
                        $hub??=ResearchR07::firstValue($comm['hub']??null);
                        if($rail===null){$rr=ResearchR07::rail($j);if($rr!==null)$rail=$rr['minutes'];}
                    }
                    $em=ResearchR07::firstValue($j['employment_type']??null);
                    if($em!==null)$employment[$em]=true;
                }
                $facts[]='最寄り駅：'.($station??'未確認').($walk!==null?'／徒歩'.$walk.'分':'／所要未確認');
                $facts[]='比較先副都心：'.($hub??'未確認').($rail!==null?'／電車'.$rail.'分':'／所要未確認');
                $facts[]='収録求人の雇用形態：'.($employment!==[]?implode('・',array_keys($employment)):'未確認');
                $compare=['企業名'=>$name,'所在地'=>$area,'最寄り駅'=>$station??'未確認','比較先副都心'=>$hub??'未確認','雇用形態'=>implode('・',array_keys($employment)),'収録求人数'=>(string)count(ResearchR07::companyJobs((string)($r['company_id']??'')))];
            }else{
                $facts[]='会社情報：'.(string)($row['legal_name']??$row['display_name']??'未確認');
                $facts[]='所在地・交通：詳細ページを参照';$facts[]='募集形態：詳細ページを参照';
                $compare=['企業名'=>$name,'会社概要'=>(string)($row['description']??'')];
            }
        }else{
            $company=$r!==null?ResearchR07::company((string)($r['company_id']??'')):null;
            $employer=$company!==null?ResearchR07::displayName($company):'企業未確認';
            if($company===null && is_array($row) && is_string($row['company_id']??null)){
                $q=$db->prepare('SELECT display_name FROM companies WHERE id=? LIMIT 1');
                $q->execute([$row['company_id']]);$companyLabel=$q->fetchColumn();
                if(is_string($companyLabel)&&$companyLabel!=='')$employer=$companyLabel;
            }
            if($r!==null){
                $employment=ResearchR07::firstValue($r['employment_type']??null)??'未確認';
                $jobDesc=ResearchR07::firstValue($r['r07_batch6_research']['job_scope']??null)
                    ??ResearchR07::firstValue($r['recheck_r07']['work_content']??null)
                    ??ResearchR07::jobTitle($r);
                $salaryFacts=ResearchR07::jobStructuredFacts($r);$pay=null;
                foreach(['専門学校卒初任給（月額）','専門学校2年制卒初任給（公式募集要項）','月給合計（原典内訳）'] as $key){if(is_string($salaryFacts[$key]??null)){$pay=$salaryFacts[$key];break;}}
                $pay??='初任給未確認（別条件の給与は混同しません）';
                $facts=['所属企業：'.$employer,'募集形態：'.$employment,'職務内容：'.$jobDesc,'初任給：'.$pay];
                $compare=['求人名'=>$name,'所属企業'=>$employer,'雇用形態'=>$employment,'仕事内容'=>$jobDesc,'初任給'=>$pay,'勤務地'=>(string)(ResearchR07::firstValue($r['worksite']??null)??''),'募集状況'=>(string)(ResearchR07::firstValue($r['recruitment_status']??null)??''),'応募締切'=>(string)(ResearchR07::firstValue($r['deadline_on']??null)??'')];
            }else{
                $facts=['所属企業：'.$employer,'募集形態：要確認','職務内容：'.$name,'初任給：未確認'];
                $compare=['企業'=>$employer,'求人名'=>$name,'求人情報'=>(string)($row['note']??'')];
            }
        }
        $digest=hash('sha256',json_encode([$r,$row],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
        return ['id'=>$id,'name'=>mb_substr($name,0,160),'url'=>$url,'facts'=>array_values(array_filter($facts,'is_string')),'snapshot'=>$compare,'hash'=>$digest];
    }
    private static function describe(array $old,array $new):string {
        $labels=[];
        foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $key){
            if(($old[$key]??null)!==($new[$key]??null))$labels[]=$key;
        }
        return $labels!==[]?'変更項目：'.implode('、',array_slice($labels,0,6)):'収録情報が更新されました（原典を確認してください）';
    }
    /** Inserts one private system message per account, record and content revision. */
    private static function inform(PDO $db,int $uid,string $kind,array $now,array $past):string {
        $diff=self::describe(is_array($past['snapshot']??null)?$past['snapshot']:[],$now['snapshot']);
        $key='vchg:'.$uid.':'.($kind==='companies'?'c':'j').':'.$now['id'].':'.substr($now['hash'],0,16);
        $subject=mb_substr(($kind==='companies'?'企業':'求人').'「'.$now['name'].'」が更新されました',0,80);
        $body=$subject."\n".$diff."\n確認先：".$now['url']."\n更新データの内容を確認してください。募集受付が継続していることを保証しません。";
        $q=$db->prepare('INSERT INTO messages(kind,sender_user_id,subject,body,system_event_key) VALUES (\'system\',NULL,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $q->execute([$subject,mb_substr($body,0,990),$key]);
        $mid=(int)$db->lastInsertId();
        $db->prepare('INSERT IGNORE INTO message_recipients(message_id,recipient_user_id) VALUES (?,?)')->execute([$mid,$uid]);
        return $diff;
    }
    /** Return prior-view time and a one-time uk-alert; then record this page view. */
    public static function visit(PDO $db,int $uid,string $kind,string $id):string {
        if(!self::valid($kind,$id))return '';
        try{
            $current=self::item($db,$kind,$id);if($current===null)return '';
            $db->beginTransaction();
            $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$uid]);if(!$q->fetchColumn())throw new \RuntimeException('Inactive account');
            $all=self::read($db,$uid,true);$previous=$all[$kind][$id]??null;
            $before=is_array($previous)?'<p class="history-last" role="status">前回の閲覧：'.Security::h(self::relative((string)$previous['at'])).'</p>':'';
            if(is_array($previous)&&$previous['hash']!==$current['hash']){
                $diff=self::inform($db,$uid,$kind,$current,$previous);
                $before.='<div class="uk-alert uk-alert-primary content-update-alert" role="status"><strong>このページの情報が更新されました</strong><p>'.Security::h($diff).'。更新を検出した日時：'.Security::h((new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i')).' JST'.'</p></div>';
            }elseif(is_array($previous)&&is_string($previous['pending']??null)&&$previous['pending']!==''){
                $before.='<div class="uk-alert uk-alert-primary content-update-alert" role="status"><strong>前回から情報が更新されています</strong><p>'.Security::h($previous['pending']).'</p></div>';
            }
            $all[$kind][$id]=['at'=>gmdate('Y-m-d\TH:i:s\Z'),'hash'=>$current['hash'],'snapshot'=>$current['snapshot']];
            self::save($db,$uid,$all);$db->commit();return $before;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();error_log('KMM view history write failed: '.get_class($e));return '';}
    }
    /** Lazy delivery on authenticated page load. A release/data change is notified at next visit, without SMTP/cron. */
    public static function poll(PDO $db,int $uid):void {
        try{
            $data=self::read($db,$uid);$updates=[];
            foreach(['companies','jobs'] as $kind)foreach($data[$kind] as $id=>$old){
                $now=self::item($db,$kind,$id);if($now!==null&&($old['hash']??'')!==$now['hash']&&($old['alerted_hash']??'')!==$now['hash'])$updates[]=[$kind,$id,$now];
                if(count($updates)>=20)break 2;
            }
            if($updates===[])return;
            $db->beginTransaction();$q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$uid]);if(!$q->fetchColumn())throw new \RuntimeException('Inactive account');
            $data=self::read($db,$uid,true);
            foreach($updates as [$kind,$id,$now]){
                $old=$data[$kind][$id]??null;
                if(!is_array($old)||($old['hash']??'')===$now['hash']||($old['alerted_hash']??'')===$now['hash'])continue;
                $diff=self::inform($db,$uid,$kind,$now,$old);$data[$kind][$id]['alerted_hash']=$now['hash'];$data[$kind][$id]['pending']=$diff;
            }
            self::save($db,$uid,$data);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();error_log('KMM content change poll failed: '.get_class($e));}
    }
    public static function gallery(PDO $db,int $uid):string {
        $data=self::read($db,$uid);$html='<section class="section recent-history"><h2>閲覧履歴</h2><p class="note">本人専用。企業と求人をそれぞれ最新50件まで保存します。</p>';
        foreach(['companies'=>'最近見た企業','jobs'=>'最近見た仕事'] as $kind=>$label){
            $entries=$data[$kind];uasort($entries,static fn($a,$b)=>strcmp((string)($b['at']??''),(string)($a['at']??'')));
            $galleryId='recent-'.$kind;
            $html.='<div class="recent-history-group"><div class="recent-header"><h3>'.Security::h($label).'</h3><div><button type="button" class="btn ghost" data-history-scroll="-1" data-history-target="'.$galleryId.'" aria-label="左に移動">‹</button><button type="button" class="btn ghost" data-history-scroll="1" data-history-target="'.$galleryId.'" aria-label="右に移動">›</button></div></div><div class="recent-rail" id="'.$galleryId.'" tabindex="0">';
            foreach($entries as $id=>$entry){try{$item=self::item($db,$kind,(string)$id);}catch(\Throwable $e){error_log('KMM history item unavailable: '.get_class($e));continue;}if($item===null)continue;
                $html.='<article class="panel recent-card"><h4>'.Security::h($item['name']).'</h4>';
                foreach(array_slice($item['facts'],0,4) as $f)if($f!=='')$html.='<p>'.Security::h(mb_substr($f,0,120)).'</p>';
                $html.='<small>閲覧：'.Security::h(self::relative((string)$entry['at'])).'</small><a class="btn ghost" href="'.Security::h($item['url']).'">詳細情報を見る</a></article>';
            }
            if($entries===[])$html.='<p class="note">まだ閲覧履歴がありません。</p>';
            $html.='</div></div>';
        }
        return $html.'</section>';
    }
}
