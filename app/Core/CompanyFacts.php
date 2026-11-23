<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Provenance-first employer claims; no inference from freeform company descriptions. */
final class CompanyFacts {
    public const TOPICS=['salary','holidays','overtime','benefits','finance','history','technical_process','reviews','social'];
    public const LABELS=['salary'=>'給与・手当','holidays'=>'休日・休暇','overtime'=>'残業・勤務時間','benefits'=>'福利厚生','finance'=>'財務・業績','history'=>'企業沿革','technical_process'=>'製造・開発工程','reviews'=>'口コミ・体験談','social'=>'SNS・公式発信'];
    public static function normalize(array $raw):array {
        $topic=BusinessData::select($raw['topic']??'',self::TOPICS);
        $label=BusinessData::text($raw['label']??null,150,true);
        $claim=BusinessData::text($raw['claim_text']??null,1200,true);
        $source=BusinessData::id(is_string($raw['source_id']??null)?$raw['source_id']:'');
        $on=BusinessData::optionalDate($raw['context_on']??'');
        $visibility=BusinessData::select($raw['publication_state']??'private',['private','public']);
        return compact('topic','label','claim','source','on','visibility');
    }
    public static function list(PDO $db,string $companyId,bool $public=true):array {
        $where=$public?" AND f.publication_state='public' AND f.archived_at IS NULL AND s.publication_state='public' AND s.archived_at IS NULL AND c.visibility='published'":" AND f.archived_at IS NULL";
        $q=$db->prepare('SELECT f.*,s.title AS source_title,s.source_type,s.source_url,s.observed_on FROM company_fact_claims f JOIN sources s ON s.id=f.source_id JOIN companies c ON c.id=f.company_id WHERE f.company_id=?'.$where.' ORDER BY f.topic,f.created_at DESC LIMIT 120');
        $q->execute([BusinessData::id($companyId)]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Insert/edit is administrator-only at controller level; never public through a proposal directly. */
    public static function create(PDO $db,string $companyId,int $actor,array $raw):string {
        if($actor<1)throw new \InvalidArgumentException('Invalid actor');
        $companyId=BusinessData::id($companyId);$v=self::normalize($raw);
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT visibility FROM companies WHERE id=? FOR UPDATE');$q->execute([$companyId]);$companyState=$q->fetchColumn();
            if(!is_string($companyState)||$companyState==='archived')throw new \InvalidArgumentException('Unavailable company');
            $q=$db->prepare('SELECT publication_state,archived_at FROM sources WHERE id=?');$q->execute([$v['source']]);$sr=$q->fetch(PDO::FETCH_ASSOC);
            if(!$sr||$sr['archived_at']!==null)throw new \InvalidArgumentException('Source missing');
            $q=$db->prepare('SELECT 1 FROM company_sources WHERE company_id=? AND source_id=?');$q->execute([$companyId,$v['source']]);
            if(!$q->fetchColumn())throw new \InvalidArgumentException('Source must be linked to this company');
            if($v['visibility']==='public' && ($companyState!=='published'||$sr['publication_state']!=='public'))throw new \InvalidArgumentException('Publication requires public company and source');
            $id=BusinessData::id();
            $q=$db->prepare('INSERT INTO company_fact_claims(id,company_id,source_id,topic,label,claim_text,context_on,publication_state,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$id,$companyId,$v['source'],$v['topic'],$v['label'],$v['claim'],$v['on'],$v['visibility'],$actor]);
            $q=$db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)');
            $q->execute([$actor,'company_fact_claims',$id,'create',null,json_encode(['topic'=>$v['topic'],'visibility'=>$v['visibility'],'source_id'=>$v['source']],JSON_THROW_ON_ERROR)]);
            $db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function archive(PDO $db,string $companyId,string $factId,int $revision,int $actor):void {
        if($revision<1||$actor<1)throw new \InvalidArgumentException('Invalid version');
        $companyId=BusinessData::id($companyId);$factId=BusinessData::id($factId);
        $db->beginTransaction();
        try {
            $q=$db->prepare('UPDATE company_fact_claims SET archived_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=? AND company_id=? AND revision=? AND archived_at IS NULL');$q->execute([$factId,$companyId,$revision]);
            if($q->rowCount()!==1)throw new \RuntimeException('Fact was changed or archived');
            $q=$db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,before_json,after_json) VALUES (?,?,?,?,?,?)');
            $q->execute([$actor,'company_fact_claims',$factId,'archive',null,'{"archived":true}']);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function html(array $rows):string {
        if(!$rows)return '<p class="note">根拠が紐付いた公開データはまだありません。未確認の待遇や財務情報を補完しません。</p>';
        $result='';$topic='';
        foreach($rows as $r){
            if($r['topic']!==$topic){if($topic!=='')$result.='</div>';$topic=$r['topic'];$result.='<h3>'.Security::h(self::LABELS[$topic]??'参考情報').'</h3><div class="cards">';}
            $result.='<article class="panel"><strong>'.Security::h($r['label']).'</strong><p>'.Security::h($r['claim_text']).'</p><p>時点：'.Security::h($r['context_on']??$r['observed_on']??'不明').'</p><p>出典：'.Security::h($r['source_title']).'</p>';
            // Escaping the attribute alone does not disarm javascript: or other unsafe URL schemes.
            if(ResearchExternalLinks::safeUrl($r['source_url']??null))$result.='<a href="'.Security::h($r['source_url']).'" target="_blank" rel="noopener noreferrer nofollow">根拠URLを確認</a>';
            if(in_array($topic,['reviews','social'],true))$result.='<p class="note">体験談やSNS投稿は、採用条件や労働実態の確定根拠ではありません。</p>';
            $result.='</article>';
        }
        return $result.'</div>';
    }
}
