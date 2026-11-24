<?php
declare(strict_types=1);
namespace KMM\Core;
/** Read-only provenance-bound supplementary facts, and an offline research/schema gap plan.
 * The research release is accepted AS PROVIDED. This is not a factual second review.
 * No stage record is public unless a later privileged workflow explicitly authorizes it.
 */
final class ResearchSupplement {
    // Explicit allowlist: never publish AI, workflow, risk/watch notes, personal details,
    // generated prompts, or unrecognized future source keys via arbitrary JSON fields.
    public const ALLOW_FIELDS = [
        'role'=>'調査記載の対象職種', 'type'=>'調査記載の応募区分',
        'graduates'=>'卒業年度・学歴条件（調査記載）',
        'foreign'=>'外国籍応募条件（調査記載）', 'age'=>'年齢条件（調査記載）',
        'salary'=>'給与情報（調査記載）', 'strength'=>'業務・特徴（調査記載）',
        'area'=>'候補地域（実勤務地の確定ではありません）',
    ];
    private const EXISTING=['name','japanese','deadline','url','status','priority','reference'];
    private const JOB_SCOPED=['role','type','graduates','foreign','age','salary'];
    private const PRIVATE=['risk','watch','change'];
    /** Pure analysis only; even operator bindings here are unverified proposed IDs. */
    public static function plan(array $feed,array $linkPlan,array $bindings=[]):array {
        if(($feed['format']??null)!=='kmm-site-accepted-research-feed-v1'
            ||($feed['source_release_verified']??null)!==true ||($feed['research_version']??null)!=='d1.0.1'
            ||($linkPlan['version']??null)!=='d1.0.2'||($linkPlan['url_count']??null)!==141)
            throw new \DomainException('Pinned research inputs required');
        if(!is_array($bindings)||array_is_list($bindings)&&$bindings!==[])
            throw new \DomainException('Bindings must be a keyed object');
        if(count($bindings)>27)throw new \DomainException('Too many explicit binding candidates');
        $valid=[];$items=[];$private=[];$already=[];$names=[];$urls=[];$seenRids=[];$issues=[];
        $definitions=SchemaUpgrade::definitions();
        $schemaColumns=[];
        foreach(['companies','jobs','company_locations','company_fact_claims'] as $table)$schemaColumns[$table]=SchemaUpgrade::expectedColumns($definitions[$table]);
        $modeledFields=['name'=>'companies.display_name','japanese'=>'companies.legal_name','deadline'=>'jobs.deadline_on','url'=>'companies.website_url'];
        foreach($feed['records'] as $entry){
            if(!is_array($entry)||!is_string($entry['review_id']??null)
                ||preg_match('/\Ar-[a-f0-9]{24}\z/D',$entry['review_id'])!==1
                ||isset($seenRids[$entry['review_id']]))throw new \DomainException('Invalid or repeated research record ID');
            $rid=$entry['review_id'];$seenRids[$rid]=true;
            $sourceRow=$entry['source_record']??null;
            if(!is_array($sourceRow)||!hash_equals((string)($entry['source_record_sha256']??''),
                hash('sha256',json_encode($sourceRow,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))
                throw new \DomainException('Source record hash changed');
            foreach(($entry['source_claims']??[]) as $key=>$val)
                if(!array_key_exists($key,$sourceRow)||$sourceRow[$key]!==$val)
                    throw new \DomainException('Source claim changed without a new release');
            $status=$entry['site_data_status']??null;
            if(!in_array($status,['RESEARCH_ACCEPTED','RESEARCH_HOLD','RESEARCH_EXCLUDED'],true))throw new \DomainException('Research status invalid');
            $name=$entry['name']??null;
            if(!is_string($name)||$name==='')throw new \DomainException('Missing research name');
            $nameKey=self::normalizeName($name);
            if(isset($names[$nameKey]) && $names[$nameKey]!==$rid){$issues[]=['code'=>'POSSIBLE_RESEARCH_NAME_DUPLICATE','review_ids'=>[$names[$nameKey],$rid],'action'=>'manual_technical_review_no_auto_merge'];}
            $names[$nameKey]=$rid;
            $url=$entry['source_url']??null;
            if(!ResearchExternalLinks::safeUrl($url))throw new \DomainException('Unsafe research provenance URL');
            if(isset($urls[$url]) && $urls[$url]!==$rid)$issues[]=['code'=>'SHARED_RESEARCH_SOURCE_URL','review_ids'=>[$urls[$url],$rid],'action'=>'review_technical_reference_no_auto_merge'];
            $urls[$url]=$rid;
            $binding=$bindings[$rid]??null;
            if($binding!==null){
                if($status!=='RESEARCH_ACCEPTED')throw new \DomainException('HOLD/X cannot receive binding proposal');
                $binding=self::validateBinding($binding);
                $valid[$rid]=true;
            }
            $claims=$entry['source_claims']??null;
            if(!is_array($claims))throw new \DomainException('Missing source claims');
            foreach($claims as $field=>$raw){
                if(!is_string($field)||!is_string($raw))throw new \DomainException('Untrusted source value');
                if(in_array($field,self::EXISTING,true)){$already[$field]=($already[$field]??0)+1;continue;}
                if(!array_key_exists($field,self::ALLOW_FIELDS)){
                    $private[$field]=($private[$field]??0)+1;continue;
                }
                if(trim($raw)==='')continue;
                // Exact source text is preserved; presentation is escaped at the last boundary.
                $items[]=[
                    'id'=>substr(hash('sha256',$rid."\0".$field."\0".$entry['source_record_sha256']),0,32),
                    'review_id'=>$rid, 'research_version'=>$entry['research_version'],
                    'source_record_sha256'=>$entry['source_record_sha256'],
                    'source_company'=>$name, 'field_key'=>$field,
                    'target_scope'=>in_array($field,self::JOB_SCOPED,true)?'job':'company',
                    'field_label'=>self::ALLOW_FIELDS[$field], 'claim_text'=>$raw,
                    'source_url'=>$url, 'source_checked_on'=>$entry['checked_on'],
                    'research_status'=>$status,
                    'company_id'=>$binding['company_id']??null,
                    'company_revision'=>$binding['expected_company_revision']??null,
                    'job_id'=>$binding['job_id']??null,
                    'job_revision'=>$binding['expected_job_revision']??null,
                    'publication_state'=>'private',
                    'binding_status'=>$binding===null?'UNRESOLVED':'PROPOSED_UNVERIFIED',
                    'may_auto_publish'=>false,
                ];
            }
        }
        foreach($bindings as $rid=>$_)if(!is_string($rid)||!isset($valid[$rid]))throw new \DomainException('Unknown explicit review ID');
        $linkNames=[];$linkUrls=[];
        foreach($linkPlan['items'] as $link){
            $name=$link['source_company']??null;$url=$link['url']??null;
            if(!is_string($name)||!ResearchExternalLinks::safeUrl($url))throw new \DomainException('Invalid link source');
            $linkNames[self::normalizeName($name)][$name]=true;
            $linkUrls[$url][$name]=true;
        }
        foreach($linkUrls as $url=>$companies)if(count($companies)>1)$issues[]=[
            'code'=>'CROSS_COMPANY_LINK_URL','source_url_sha256'=>hash('sha256',$url),
            'company_count'=>count($companies),'action'=>'manual_technical_review_no_auto_merge'];
        foreach($linkNames as $normalized=>$companyNames)if(count($companyNames)>1)$issues[]=[
            'code'=>'POSSIBLE_LINK_COMPANY_NAME_COLLISION','names'=>array_keys($companyNames),
            'action'=>'manual_technical_review_no_auto_merge'];
        return ['format'=>'kmm-research-supplement-gap-v1','research_version'=>'d1.0.1',
            'research_links_version'=>'d1.0.2','source_manifest_sha256'=>$feed['manifest_sha256'],
            'source_catalog_sha256'=>$linkPlan['source_catalog_sha256'],
            'record_count'=>count($feed['records']),'link_count'=>count($linkPlan['items']),
            'candidate_count'=>count($items),'candidate_items'=>$items,
            'private_only_fields'=>$private,'represented_existing_fields'=>$already,
            'existing_business_schema_columns'=>$schemaColumns,'existing_mapped_fields'=>$modeledFields,
            'supplement_fields_missing_specific_business_columns'=>array_keys(self::ALLOW_FIELDS),
            'technical_issues'=>$issues,'issue_count'=>count($issues),
            'live_db_compared'=>false,'database_write_performed'=>false,
            'live_company_job_id_verified'=>false,'no_auto_merge'=>true,
            'user_overlays_modified'=>false,'research_facts_rechecked'=>false,
            'all_publication_private'=>true];
    }
    private static function validateBinding(mixed $binding):array {
        if(!is_array($binding)||array_is_list($binding)
            ||array_diff(array_keys($binding),['company_id','expected_company_revision','job_id','expected_job_revision'])!==[])
            throw new \DomainException('Untrusted binding proposal');
        $cid=$binding['company_id']??null;$rev=$binding['expected_company_revision']??null;
        if(!is_string($cid)||preg_match('/\A[a-f0-9]{32}\z/D',$cid)!==1||!is_int($rev)||$rev<1||$rev>2147483646)
            throw new \DomainException('Explicit company DB ID and revision required');
        $jid=$binding['job_id']??null;$jrev=$binding['expected_job_revision']??null;
        if(($jid===null)!==($jrev===null))throw new \DomainException('Job ID/revision must be supplied together');
        if($jid!==null&&(!is_string($jid)||preg_match('/\A[a-f0-9]{32}\z/D',$jid)!==1||!is_int($jrev)||$jrev<1||$jrev>2147483646))
            throw new \DomainException('Invalid explicit job ID or revision');
        return $binding;
    }
    private static function normalizeName(string $v):string {
        return strtolower((string)preg_replace('/[\s\x{3000}]+/u','',trim($v)));
    }
    /** Use only with public rows returned by ResearchSupplementRepository. */
    public static function html(array $rows):string {
        $html='';$seen=[];
        foreach($rows as $r){
            $field=$r['field_key']??null;$text=$r['claim_text']??null;
            $requiredScope=in_array($field,self::JOB_SCOPED,true)?'job':'company';
            if(!is_string($field)||!isset(self::ALLOW_FIELDS[$field])
                ||($r['target_scope']??null)!==$requiredScope
                ||!is_string($text)||$text===''||strlen($text)>8000
                ||($r['publication_state']??null)!=='published'
                ||($r['research_status']??null)!=='RESEARCH_ACCEPTED')continue;
            $id=hash('sha256',$field."\0".$text);if(isset($seen[$id]))continue;$seen[$id]=true;
            $date=$r['source_checked_on']??'未確認';
            $url=$r['source_url']??null;
            $html.='<article class="panel supplementary-fact"><h3>'.Security::h(self::ALLOW_FIELDS[$field]).'</h3>'
                .'<p>'.nl2br(Security::h($text),false).'</p>'
                .'<p class="note">調査記載（'.Security::h(is_string($date)?$date:'未確認').'）。募集残枠・応募資格・実勤務地の保証ではありません。';
            if(ResearchExternalLinks::safeUrl($url))$html.=' <a href="'.Security::h($url).'" target="_blank" rel="noopener noreferrer nofollow external">出典（外部）</a>';
            $html.='</p></article>';
        }
        return $html===''?'':'<section class="section research-supplements"><h2>補充情報</h2>'.$html.'</section>';
    }
}
