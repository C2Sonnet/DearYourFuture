<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Site-adopted, pinned d1.0.4 R07 Batch66 current catalogue.
 * The R07 source has its own partial/not-final research-review status; site adoption
 * does not certify each job's recruiting status or applicant eligibility.
 * Research originals, user-created records, and R06 historical seed are not rewritten.
 */
final class ResearchR07 {
    public const RELEASE = 'd1.0.4 R07 Batch66（サイト採用データ集）';
    public const FILE_SHA256 = '31c5a2cc629600075cdad706ed763eadbfe7ef3cb290674b7da43ad052ce93a6';
    private static ?array $snapshot = null;
    private static ?array $lookups = null;
    private static ?array $mapping = null;
    private static ?array $reviews = null;
    private static ?array $ledger = null;
    public static function root():string {return SiteState::root().'/resources/research/r07-current';}
    public static function data():array {
        if(self::$snapshot!==null)return self::$snapshot;
        $path=self::root().'/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH66_20261124.json';
        if(!is_file($path)||!hash_equals(self::FILE_SHA256,(string)hash_file('sha256',$path)))
            throw new \RuntimeException('R07 source integrity mismatch');
        // The owner's SITE approval is distinct from the source research audit status.
        // Fail closed if an unapproved or modified catalogue is deployed.
        $siteAdoption=SiteState::root().'/resources/research/SITE_CATALOGUE_ADOPTION_v2.6.0.json';
        $approvalRaw=@file_get_contents($siteAdoption);
        if(!is_string($approvalRaw)||strlen($approvalRaw)>16384)
            throw new \RuntimeException('Site catalogue adoption record missing');
        $approval=json_decode($approvalRaw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($approval)
            ||($approval['format']??null)!=='kmm-site-catalogue-adoption-v1'
            ||($approval['site_version']??null)!=='2.6.0'
            ||($approval['site_status']??null)!=='APPROVED_SITE_CATALOGUE'
            ||($approval['source_release']??null)!=='d1.0.4 R07 Batch66'
            ||($approval['source_path']??null)!=='resources/research/r07-current/COMPANY_JOB_RELATIONS_d1.0.4_R07_BATCH66_20261124.json'
            ||($approval['source_sha256']??null)!==self::FILE_SHA256
            ||($approval['companies']??null)!==113||($approval['jobs']??null)!==146
            ||($approval['source_research_status']??null)!=='partial_not_final'
            ||($approval['user_data_overwrite']??null)!==false)
            throw new \RuntimeException('Site catalogue adoption record invalid');
        $d=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if(!is_array($d)||count($d['companies']??[])!==113||count($d['jobs']??[])!==146)
            throw new \RuntimeException('Unsupported R07 research release');
        $companies=[];$jobs=[];
        foreach($d['companies'] as $company){
            $id=$company['company_id']??null;
            if(!is_string($id)||!self::isCompanyId($id)||isset($companies[$id]))
                throw new \RuntimeException('Duplicate or invalid R07 company ID');
            $companies[$id]=true;
        }
        foreach($d['jobs'] as $job){
            $id=$job['job_id']??null;$companyId=$job['company_id']??null;
            if(!is_string($id)||!self::isJobId($id)||isset($jobs[$id])
                ||!is_string($companyId)||!isset($companies[$companyId]))
                throw new \RuntimeException('Invalid R07 job ID or company reference');
            $jobs[$id]=true;
        }
        return self::$snapshot=$d;
    }
    private static function lookups():array {
        if(self::$lookups!==null)return self::$lookups;
        $d=self::data();$m=['companies'=>[],'jobs'=>[]];
        foreach(['companies'=>'company_id','jobs'=>'job_id'] as $kind=>$key){
            foreach($d[$kind] as $record){if(!is_array($record))continue;$id=$record[$key]??null;
                if(is_string($id)&&preg_match($kind==='companies'?'/\ACO-[0-9]{4}\z/D':'/\AJOB-[0-9]{4}\z/D',$id))$m[$kind][$id]=$record;
            }
        }
        return self::$lookups=$m;
    }
    public static function companies():array {return self::lookups()['companies'];}
    public static function jobs():array {return self::lookups()['jobs'];}
    public static function company(string $sourceId):?array {return self::companies()[$sourceId]??null;}
    public static function job(string $sourceId):?array {return self::jobs()[$sourceId]??null;}
    public static function isCompanyId(string $id):bool {return preg_match('/\ACO-[0-9]{4}\z/D',$id)===1;}
    public static function isJobId(string $id):bool {return preg_match('/\AJOB-[0-9]{4}\z/D',$id)===1;}
    /** Source ID <-> canonical database UUID crosswalk from the installed R06 base snapshots. */
    public static function mapping(PDO $db):array {
        if(self::$mapping!==null)return self::$mapping;
        $m=['companies'=>[],'jobs'=>[],'companies_rev'=>[],'jobs_rev'=>[]];
        $stmt=$db->prepare("SELECT record_type,record_id,base_json FROM seed_entity_states WHERE seed_version IN ('research-d1.0.4-R06','research-d1.0.4-R07-site') AND record_type IN ('companies','jobs') LIMIT 10000");
        $stmt->execute();
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $type=(string)($row['record_type']??'');if(!isset($m[$type]))continue;
            $id=(string)($row['record_id']??'');$base=$row['base_json']??null;
            if(!is_string($base)||strlen($base)>200000)continue;
            $v=json_decode($base,true);if(!is_array($v))continue;
            $source=$v[$type==='companies'?'source_company_id':'source_job_id']??null;
            if(!is_string($source))continue;
            $m[$type][$source]=$id;$m[$type.'_rev'][$id]=$source;
            if($type==='companies')foreach(($v['source_company_aliases']??[]) as $alias)
                if(is_string($alias)&&self::isCompanyId($alias))$m['companies'][$alias]=$id;
        }
        return self::$mapping=$m;
    }
    public static function invalidateMapping():void {self::$mapping=null;}
    public static function dbId(PDO $db,string $type,string $sourceId):?string {return self::mapping($db)[$type][$sourceId]??null;}
    public static function sourceId(PDO $db,string $type,string $dbId):?string {return self::mapping($db)[$type.'_rev'][$dbId]??null;}
    public static function companyUrl(PDO $db,string $sourceId):string {
        $id=self::dbId($db,'companies',$sourceId);
        return $id!==null?'/companies/'.$id:'/companies/research/'.$sourceId;
    }
    public static function jobUrl(PDO $db,string $sourceId):string {
        $id=self::dbId($db,'jobs',$sourceId);
        return $id!==null?'/jobs/'.$id:'/jobs/research/'.$sourceId;
    }
    public static function value(mixed $value):?string {
        if(is_string($value)){$v=trim($value);return $v!==''?$v:null;}
        if(is_int($value)||is_float($value))return (string)$value;
        return null;
    }
    /** For compact factual summaries only; preserve status/verbatim strings elsewhere. */
    private static function factValue(mixed $value):?string {
        $v=self::firstValue($value);
        return $v!==null&&!in_array(mb_strtolower($v),['不明','未確認','種類不明','なし','記録なし','null','unknown','n/a','未収録'],true)?$v:null;
    }
    public static function firstValue(mixed $value):?string {
        if(is_array($value)){foreach($value as $val){$v=self::firstValue($val);if($v!==null)return $v;}return null;}
        return self::value($value);
    }
    public static function displayName(array $company):string {return self::firstValue($company['legal_name']??$company['brand']??null)??'企業名不明';}
    public static function jobTitle(array $job):string {return self::firstValue($job['job_title']??null)??'求人名不明';}
    public static function address(array $company):?string {
        $hq=$company['headquarters_recheck_r07']??[];
        $follow=$company['headquarters_recheck_r07_followup']??[];
        $v=self::firstValue(is_array($hq)?($hq['full_address']??null):null)
            ??self::firstValue(is_array($follow)?($follow['full_address']??null):null);
        if($v!==null)return $v;
        $v=self::firstValue($company['region_as_recorded_2026_11_19']??null);
        return $v;
    }
    public static function localArea(array $company):?string {
        $address=self::address($company);if($address===null)return null;
        $address=preg_replace('/\A東京[・･]/u','東京都',$address)??$address;
        $address=preg_replace('/\A神奈川[・･]/u','神奈川県',$address)??$address;
        $address=preg_replace('/\A千葉[・･]/u','千葉県',$address)??$address;
        $address=preg_replace('/\A埼玉[・･]/u','埼玉県',$address)??$address;
        if(preg_match('/\A(東京都|北海道|(?:京都|大阪)府|.{2,4}県)([^0-9０-９\s　、・ほか]{1,15}?(?:区|市|町|村))/u',$address,$m))return $m[1].$m[2];
        return null;
    }
    public static function prefecture(array $company):?string {
        $a=self::address($company);if($a===null)return null;
        $a=preg_replace('/\A東京[・･]/u','東京都',$a)??$a;
        if(preg_match('/\A(東京都|北海道|(?:京都|大阪)府|.{2,4}県)/u',$a,$m))return $m[1];
        return null;
    }
    public static function companyJobs(string $companyId):array {
        return array_values(array_filter(self::jobs(),static fn($j):bool=>($j['company_id']??null)===$companyId));
    }
    public static function firstStation(array $job):?string {
        $re=$job['recheck_r07']??[];$comm=$job['commute']??[];
        return self::factValue($re['nearest_station']??null)??self::factValue($comm['station']??null);
    }
    public static function rail(array $job):?array {
        $v=$job['commute']??[];if(!is_array($v))return null;
        $min=$v['rail_minutes_min']??null;
        if(is_numeric($min) && (float)$min>=0 && (float)$min<=300){
            $line=self::factValue($v['line']??$v['rail_line']??$v['route_line']??null);
            return ['line'=>$line,'minutes'=>(int)$min];
        }
        return null;
    }
    public static function quickFacts(array $company):string {
        $parts=[];
        $area=self::localArea($company);if($area!==null)$parts[]=$area;
        $jobs=self::companyJobs((string)($company['company_id']??''));$station=null;$hub=null;$shortest=null;$rounds=null;$employment=null;
        foreach($jobs as $j){
            $station??=self::firstStation($j);
            $c=$j['commute']??[];if(is_array($c))$hub??=self::factValue($c['hub']??null);
            $rail=self::rail($j);if($rail!==null&&($shortest===null||$rail['minutes']<$shortest['minutes']))$shortest=$rail;
            if($rounds===null){$n=$j['selection_round_count']??$j['recheck_r07']['selection_round_count']??null;if(is_int($n)&&$n>0)$rounds=$n;}
            $employment??=self::factValue($j['employment_type']??null);
        }
        if($station!==null)$parts[]=$station;
        if($hub!==null)$parts[]=$hub;
        if($shortest!==null)$parts[]=($shortest['line']!==null?$shortest['line'].' ':'電車').$shortest['minutes'].'分';
        if($rounds!==null)$parts[]='選考'.$rounds.'回';
        if($employment!==null)$parts[]=$employment;
        return implode('｜',$parts);
    }
    public static function links(array $record):array {
        $urls=[];
        foreach(['company_official_urls','recruit_urls','company_research_sources'] as $field)foreach((array)($record[$field]??[]) as $val)if(ResearchExternalLinks::safeUrl($val))$urls[$val]=$field;
        foreach(['source_url','employment_type_evidence_url','identity_evidence_url'] as $field){$v=$record[$field]??null;if(ResearchExternalLinks::safeUrl($v))$urls[$v]=$field;}
        $head=$record['headquarters_recheck_r07']??[];if(is_array($head)&&ResearchExternalLinks::safeUrl($head['source_url']??null))$urls[$head['source_url']]='headquarters';
        $review=$record['employee_review_evidence_r07_batch3']??[];if(is_array($review)&&ResearchExternalLinks::safeUrl($review['source_url']??null))$urls[$review['source_url']]='review';
        $re=$record['recheck_r07']??[];if(is_array($re)&&ResearchExternalLinks::safeUrl($re['status_basis_url']??null))$urls[$re['status_basis_url']]='recheck';
        // Later research batches can add separate course/source URLs without overwriting the original job.
        $b6=$record['r07_batch6_research']??[];
        if(is_array($b6))foreach(['source_url','school_2027_course_url','original_job_url','original_job_index_source'] as $field){
            $u=$b6[$field]??null;
            if(ResearchExternalLinks::safeUrl($u))$urls[$u]=match($field){
                'school_2027_course_url'=>'other_course',
                'original_job_index_source','original_job_url'=>'source_url',
                default=>'source_url'
            };
        }
        foreach(['r07_batch4_ivy_official_2027','r07_batch4_establishment_source'] as $nested){
            $d=$record[$nested]??[];
            if(is_array($d) && ResearchExternalLinks::safeUrl($d['source_url']??null))
                $urls[$d['source_url']]='other_course';
        }
        // Preserve Batch42 nested public provenance without creating separate update pages.
        // Do not infer that a linked advertisement is currently accepting applications.
        $walk=static function(mixed $value,int $depth,string $context)use(&$walk,&$urls):void {
            if($depth>4||count($urls)>=160||!is_array($value))return;
            foreach($value as $key=>$child){
                $name=is_string($key)?strtolower($key):(string)$key;
                if(is_string($child)&&ResearchExternalLinks::safeUrl($child)
                    && preg_match('/(?:url|source|link|ref|evidence|official|page)/i',$name)){
                    $urls[$child]=preg_match('/(?:other|separate|midcareer|another|different|related)/i',$context.' '.$name)?'other_course':'source_url';
                }elseif(is_array($child))$walk($child,$depth+1,$context.'.'.$name);
            }
        };
        $walk($record,0,'research');
        return $urls;
    }
    public static function linkButtons(array $urls,string $label='原典・出典ページ'):string {
        $html='';$seen=[];
        foreach($urls as $url=>$kind){
            if(!ResearchExternalLinks::safeUrl($url)||isset($seen[$url]))continue;$seen[$url]=true;
            $name=is_string($kind)?match($kind){'company_official_urls'=>'公式サイト','recruit_urls'=>'採用サイト','company_research_sources'=>'企業情報','review'=>'口コミ原典','headquarters'=>'所在地原典','source_url','recheck'=>'求人原典','other_course'=>'別採用コース（求人と同一とは限りません）',default=>$label}:$label;
            $html.='<a class="btn ghost external-link" href="'.Security::h($url).'" target="_blank" rel="noopener noreferrer nofollow external">'.Security::h($name).' ↗</a>';
        }
        return $html===''?'<p>原典URL未収録</p>':'<div class="r07-source-links">'.$html.'</div>';
    }
    public static function pairTable(array $pairs):string {
        $body='';foreach($pairs as $label=>$value){$v=self::firstValue($value);if($v===null)continue;
            $body.='<tr><th scope="row">'.Security::h((string)$label).'</th><td>'.Security::h($v).(preg_match('/所在地|住所|勤務地|勤務予定地/u',(string)$label)?GoogleMapsLink::button($v):'').'</td></tr>';
        }
        return $body===''?'<p>情報未収録</p>':'<div class="r07-table-wrap"><table class="r07-fact-table"><tbody>'.$body.'</tbody></table></div>';
    }
    private static function reviews():array {
        if(self::$reviews!==null)return self::$reviews;
        $path=self::root().'/COMPANY_REVIEW_CURRENT.csv';
        $out=[];$f=fopen($path,'rb');if($f===false)return [];
        $head=fgetcsv($f,0,',','"','\\');if(!is_array($head)){fclose($f);return [];}
        // The research CSV is UTF-8 with BOM; remove it from its first header key.
        if(isset($head[0]) && is_string($head[0]))$head[0]=preg_replace('/\A\xEF\xBB\xBF/','',$head[0]);
        while(($row=fgetcsv($f,0,',','"','\\'))!==false){$r=array_combine($head,array_pad($row,count($head),''));if(is_array($r)&&self::isCompanyId($r['company_id']??''))$out[$r['company_id']]=$r;}
        fclose($f);return self::$reviews=$out;
    }
    public static function reviewHTML(string $sourceCompanyId):string {
        $r=self::reviews()[$sourceCompanyId]??[];
        $co=self::company($sourceCompanyId);$extra=$co['employee_review_evidence_r07_batch38']??$co['employee_review_evidence_r07_batch37']??$co['employee_review_evidence_r07_batch24']??$co['employee_review_evidence_r07_batch23']??$co['employee_review_evidence_r07_batch9']??$co['employee_review_evidence_r07_batch8']??$co['employee_review_evidence_r07_batch7']??$co['employee_review_evidence_r07_batch6']??$co['employee_review_evidence_r07_batch5']??$co['employee_review_evidence_r07_batch4']??$co['employee_review_evidence_r07_batch3']??[];
        if(!is_array($extra))$extra=[];
        $positive=self::firstValue($r['positive_review']??null)??self::firstValue($extra['positive_summary']??$extra['positive_review_summary']??null);
        $negative=self::firstValue($r['negative_review']??null)??self::firstValue($extra['negative_summary']??$extra['negative_review_summary']??null);
        $url=self::firstValue($r['source_url']??null)??self::firstValue($extra['source_url']??$extra['url']??null);
        // Some source-only rows contain editorial placeholders ("本文未取得") in review columns.
        // They are NOT actual positive/negative review testimony; label as a source index only.
        if(($r['status']??null)==='review_public_source_located_text_unread'){$positive=null;$negative=null;}
        if($positive===null&&$negative===null&&$url===null)return '';
        $html='<section class="section"><h2>企業の口コミ情報</h2>'.self::pairTable([
            '肯定的な口コミ要約'=>$positive,'否定的な口コミ要約'=>$negative,
            '対象職種'=>self::firstValue($r['roles']??null)??self::firstValue($extra['scope']??$extra['roles_or_locations']??$extra['roles_scope']??null),
            '投稿日・対象期間'=>self::firstValue($r['published_sample_dates']??null)??self::firstValue($extra['published_samples']??$extra['date_samples']??$extra['sample_dates']??null),
            '口コミ掲載状態（原典の記録）'=>($positive===null&&$negative===null)?'口コミの掲載先のみ記録されています。本文・評価は収録されていません。':null,
        ]);
        if(ResearchExternalLinks::safeUrl($url))$html.=self::linkButtons([$url=>'review']);
        return $html.'<p class="note">口コミは投稿者の意見であり、求人職種・店舗や現在の勤務条件の確定情報ではありません。</p></section>';
    }
    public static function companyDetails(PDO $db,string $sourceId,bool $standalone=false):string {
        $co=self::company($sourceId);if($co===null)return '<p>企業記録がありません。</p>';
        $me=Identity::current();
        $favorite=$me!==null?WorkspaceController::favoriteButton($sourceId,in_array($sourceId,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'companies'),true),self::companyUrl($db,$sourceId),'companies'):'';
        $html=$standalone?'<section class="hero compact"><h1>'.Security::h(self::displayName($co)).'</h1>'.self::compareButton('companies',$sourceId).$favorite.'</section>':'';
        $hq=$co['headquarters_recheck_r07']??[];if(!is_array($hq))$hq=[];
        $details=$co['company_details']??[];if(!is_array($details))$details=[];
        $html.=ResearchCompanyPresentation::render($sourceId,$co);
        $html.='<section class="section"><h2>所在地の確認</h2>'.self::pairTable([
            '本社・登記所在地'=>$hq['full_address']??null,
            '所在地に関する注意事項'=>$hq['scope_note']??null,
        ]);
        if(ResearchExternalLinks::safeUrl($hq['source_url']??null))$html.=self::linkButtons([$hq['source_url']=>'headquarters']);
        $html.='</section>';
        $html.=self::reviewHTML($sourceId);
        $html.='<section class="section"><h2>求人情報</h2><div class="r07-related-list">';
        foreach(self::companyJobs($sourceId) as $j){$jobId=(string)$j['job_id'];$html.='<article class="panel"><h3><a href="'.Security::h(self::jobUrl($db,$jobId)).'">'.Security::h(self::jobTitle($j)).'</a></h3><p>'.Security::h(self::firstValue($j['employment_type']??null)??'').'</p></article>';}
        $html.='</div></section><section class="section"><h2>企業原典・出典</h2>'.self::linkButtons(self::links($co)).'</section>';
        return $html;
    }
    /** Values observed in research. Preserve course/period and evidence distinction. No estimate from free-text. */
    private static function yen(mixed $v):?string {
        return is_int($v)&&$v>0&&$v<10000000?number_format($v).'円':null;
    }
    private static function countLabel(mixed $v,string $unit):?string {
        return is_int($v)&&$v>=0&&$v<=10000?$v.$unit:null;
    }
    public static function jobStructuredFacts(array $job):array {
        $facts=[];
        $batch=$job['r07_batch6_research']??[];
        if(is_array($batch)&&$batch!==[]){
            $isOther=isset($batch['school_course_professional_two_year_salary_jpy']);
            $scope=$isOther?'【別公式コース・元求人との同一性未確認】':'【東京パティシエ職の公開コース】';
            if($isOther){
                $facts['出典コース区分']=$scope;
                $facts['専門学校2年制卒初任給（別公式コース）']=self::yen($batch['school_course_professional_two_year_salary_jpy']??null);
                $facts['給与の基準年度・適用条件']=$batch['school_course_salary_basis']??null;
                $facts['年間所定休日（別公式コース）']=self::countLabel($batch['school_course_annual_days_off']??null,'日');
                $facts['募集対象（別公式コース）']=$batch['school_course_target']??null;
                $facts['職務内容（別公式コース）']=$batch['school_course_job_scope']??null;
                $facts['勤務シフト（別公式コース）']=$batch['school_course_schedule']??null;
                $facts['原求人の照合状況']=$batch['note']??null;
            }else{
                $facts['出典コース区分']=$scope;
                $facts['専門学校卒初任給（月額）']=self::yen($batch['salary_jpy_specialist']??null);
                $facts['基本給（月額・専門卒）']=self::yen($batch['basic_monthly_jpy_specialist']??null);
                $facts['固定残業代（月額・専門卒）']=self::yen($batch['fixed_overtime_jpy_specialist']??null);
                $facts['固定残業時間（月間）']=self::countLabel($batch['fixed_overtime_hours_month']??null,'時間');
                $facts['地域手当（月額）']=self::yen($batch['district_allowance_jpy']??null);
                $facts['給与内訳・超過残業代']=$batch['allowance_components_note']??null;
                $facts['年間休日']=self::countLabel($batch['annual_days_off']??null,'日');
                $facts['応募対象学歴']=isset($batch['school_eligibility_explicit'])&&is_array($batch['school_eligibility_explicit'])?implode('・',$batch['school_eligibility_explicit']):null;
                $facts['勤務開始時刻の条件']=$batch['start_hour_requirement']??null;
                $facts['実際の勤務先']=$batch['worksite_address_public']??null;
                $facts['最寄駅']=$batch['nearest_station']??null;
                $facts['駅からの徒歩']=self::countLabel($batch['station_walk_minutes']??null,'分');
                $facts['個別応募・募集受付の状態']=$batch['recruitment_status']??null;
            }
        }
        $ivy=$job['r07_batch4_ivy_official_2027']??[];
        if(is_array($ivy)&&$ivy!==[]){
            $facts['専門学校2年制卒初任給（公式募集要項）']=self::yen($ivy['monthly_salary_two_year_specialist_yen']??null);
            $facts['全国転勤コースの月給（別条件）']=self::yen($ivy['monthly_salary_national_transfer_track_yen']??null);
            $facts['年間休日（公式募集要項）']=self::countLabel($ivy['annual_holidays']??null,'日');
            $facts['時間外手当（公式募集要項）']=$ivy['overtime_pay']??null;
            $facts['応募可能卒業時期']=$ivy['eligible_graduation']??null;
            $facts['勤務地・職種の範囲']=$ivy['job_type']??null;
            $facts['転勤区分']=$ivy['transfer']??null;
            $facts['公式募集の注意事項']=$ivy['vacancy_warning']??null;
        }
        $re=$job['recheck_r07']??[];$comp=is_array($re)?($re['compensation_components']??[]):[];
        if(is_array($comp)&&$comp!==[]){
            $facts['月給合計（原典内訳）']=self::yen($comp['total']??null);
            $facts['基本給（原典内訳）']=self::yen($comp['base']??null);
            $facts['固定残業代（原典内訳）']=self::yen($comp['fixed_overtime']??null);
            $facts['ライフプラン手当（原典内訳）']=self::yen($comp['life_plan']??null);
        }
        $other=$job['r07_batch4_establishment_source']??[];
        if(is_array($other)&&$other!==[]){
            $facts['給与範囲（別の一般募集要項）']=$other['salary_range_yen']??null;
            $facts['月間休日（別の一般募集要項）']=self::countLabel($other['holiday_monthly_days']??null,'日');
            $facts['募集要項の対象・注意事項']=$other['recruitment_info']??null;
            $facts['勤務地（一般募集要項）']=$other['full_worksite']??null;
        }
        foreach(['r06_details'=>'R06','r04_details'=>'R04','r05_details'=>'R05'] as $key=>$prefix){
            $extra=$job[$key]??[];
            if(is_array($extra)&&self::value($extra['salary']??null)!==null)
                $facts['給与原文（'.$prefix.'・コース別条件に注意）']=$extra['salary'];
        }
        return array_filter($facts,static fn($v)=>is_string($v)&&trim($v)!=='');
    }
    public static function jobDetails(PDO $db,string $sourceId,bool $standalone=false):string {
        $j=self::job($sourceId);if($j===null)return '<p>求人記録がありません。</p>';
        $name=self::jobTitle($j);$comm=$j['commute']??[];$re=$j['recheck_r07']??[];
        if(!is_array($comm))$comm=[];if(!is_array($re))$re=[];
        $me=Identity::current();
        $favorite=$me!==null?WorkspaceController::favoriteButton($sourceId,in_array($sourceId,WorkspaceRepository::favoriteKeys($db,(int)$me['id'],'jobs'),true),self::jobUrl($db,$sourceId),'jobs'):'';
        $html=$standalone?'<section class="hero compact"><h1>'.Security::h($name).'</h1>'.self::compareButton('jobs',$sourceId).$favorite.'</section>':'';
        $html.=ResearchR07JobView::render($sourceId,$j);
        if($standalone){$html.='<section class="section"><h2>選考フロー</h2>';
        if(ResearchExternalLinks::safeUrl($j['source_url']??null))$html.='<p>選考フローが未収録です。求人原典を確認してください。</p>'.self::linkButtons([$j['source_url']=>'source_url']);
        $html.='</section>';}
        $html.='<section class="section"><h2>原典・出典</h2>'.self::linkButtons(self::links($j)).'</section>';
        $html.='<p><a class="btn ghost" href="'.Security::h(self::companyUrl($db,(string)$j['company_id'])).'">企業詳細を見る</a></p>';
        return $html;
    }
    public static function compareButton(string $type,string $sourceId):string {
        if(Identity::current()===null)return '';
        $type=$type==='jobs'?'jobs':'companies';$id=Security::h($sourceId);
        return '<button type="button" class="btn ghost compare-add" data-compare-add data-compare-type="'.$type.'" data-compare-id="'.$id.'" data-compare-name="'.Security::h($type==='jobs'?self::jobTitle(self::job($sourceId)??[]):self::displayName(self::company($sourceId)??[])).'" aria-pressed="false">比較</button>';
    }
    public static function sourceLibrary(PDO $db,?string $companyDbId=null):string {
        if(Identity::current()===null)return '';
        $target=$companyDbId===null?null:self::sourceId($db,'companies',$companyDbId);
        if($companyDbId!==null && $target===null)return '<section class="section"><h2>企業の原典・資料</h2><p>R07に対応する原典記録はありません。</p></section>';
        $list=[];
        foreach(self::companies() as $id=>$co){
            if($target!==null&&$target!==$id)continue;
            foreach(self::links($co) as $url=>$kind)$list[$id.'|'.$url]=[$id,$url,$kind];
            foreach(self::companyJobs($id) as $j)foreach(self::links($j) as $url=>$kind)$list[$id.'|'.$url]=[$id,$url,$kind];
        }
        $page=filter_var($_GET['research_page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]])?:1;
        $q=is_string($_GET['research_q']??null)?mb_substr(trim($_GET['research_q']),0,100):'';
        if($q!=='')$list=array_filter($list,function(array $r)use($q){return mb_stripos(self::displayName(self::company($r[0])??[]).' '.$r[1],$q)!==false;});
        $html='<section class="section"><h2>研究資料の原典・出典リンク（R07 Batch46）</h2><p class="note">収録済みの参照先を表示します。PDFや画像の実体が提供されていないリンクは、ファイルとして再配布しません。</p>';
        $html.='<form method="get" class="directory-filters"><label>原典URL・企業名検索<input name="research_q" value="'.Security::h($q).'" maxlength="100"></label><button class="btn primary">検索</button></form>';
        $current=array_slice(array_values($list),($page-1)*25,25);
        $html.='<div class="compact-card-grid library-card-grid">';
        foreach($current as [$id,$url,$kind])$html.='<article class="panel library-row"><h3>'.Security::h(self::displayName(self::company($id)??[])).'</h3><p>'.Security::h($kind).'</p>'.self::linkButtons([$url=>$kind]).'</article>';
        if(!$current)$html.='<p>該当する出典はありません。</p>';
        $html.='</div>';
        if($page>1)$html.='<a class="btn ghost" href="?'.Security::h(http_build_query(['research_q'=>$q,'research_page'=>$page-1])).'">前へ</a>';
        if(count($current)===25)$html.='<a class="btn ghost" href="?'.Security::h(http_build_query(['research_q'=>$q,'research_page'=>$page+1])).'">次へ</a>';
        return $html.'</section>';
    }
}
