<?php
declare(strict_types=1);
namespace KMM\Core;

/** Auditable read-only research delta review. No DB, publish, notification, or applicant writes. */
final class ResearchDeltaReview {
    private const VERSION='d1.0.1';
    // Trust anchor for the exact released research manifest. Its listed file hashes
    // alone are not sufficient: an attacker could replace the manifest and files
    // together while keeping their internal checksum consistency.
    public const MANIFEST_SHA256='d85f1a8063fc4093173f9c0a06d3257f40e463d918ac48a5394235219a32ad2d';
    private const DATE='2026-11-19';
    private const TYPES=['new_companies'=>14,'existing_updates'=>13];
    private const PRIORITIES=['S','A','B','HOLD','X'];

    public static function review(string $manifestBytes, string $updatesBytes, string $reportBytes): array {
        if(!hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestBytes)))
            throw new \DomainException('Unsupported or modified research manifest release');
        $manifest=self::decode($manifestBytes);
        if(($manifest['data_version']??null)!==self::VERSION
            ||($manifest['source_baseline']??null)!=='d1.0.0'
            ||($manifest['checked_at']??null)!==self::DATE
            ||($manifest['subproject']??null)!=='情報収集'
            ||($manifest['status']??null)!=='released_verified')
            throw new \DomainException('Unexpected research manifest identity or release state');
        if(!isset($manifest['files'])||!is_array($manifest['files'])||count($manifest['files'])!==2)
            throw new \DomainException('Research manifest must list both original source documents');
        $known=[];
        foreach($manifest['files'] as $file){
            if(!is_array($file)||!is_string($file['file']??null)||isset($known[$file['file']]))
                throw new \DomainException('Duplicate or malformed research file metadata');
            $known[$file['file']]=$file;
        }
        $sources=[
            'COMPANIES_UPDATES_d1.0.1.json'=>$updatesBytes,
            '企業再調査_新規候補_d1.0.1_2026-11-19.md'=>$reportBytes,
        ];
        if(count(array_diff(array_keys($known),array_keys($sources)))>0 || count(array_diff(array_keys($sources),array_keys($known)))>0)
            throw new \DomainException('Manifest source set mismatch');
        foreach($sources as $name=>$bytes){
            $meta=$known[$name]??null;
            if(!is_array($meta)||!is_int($meta['bytes']??null)||!is_string($meta['sha256']??null)
                ||strlen($meta['sha256'])!==64||strlen($bytes)!==$meta['bytes']
                ||!hash_equals(strtolower($meta['sha256']),hash('sha256',$bytes)))
                throw new \DomainException('Research provenance file mismatch: '.$name);
            self::https((string)($meta['drive_url']??''));
        }
        $data=self::decode($updatesBytes);
        if(($data['version']??null)!==self::VERSION||($data['checked_at']??null)!==self::DATE
            ||($data['subproject']??null)!=='情報収集'
            ||($data['baseline']??null)!=='d1.0.0 (2026-11-18 research)')
            throw new \DomainException('Research delta identity mismatch');
        if(($manifest['research_counts']['new_company_or_candidate_assessments']??null)!==14
           ||($manifest['research_counts']['existing_company_rechecks']??null)!==13)
            throw new \DomainException('Manifest counts conflict with research release');
        $entries=[];$seen=[];$seq=0;
        foreach(self::TYPES as $kind=>$expected){
            $list=$data[$kind]??null;
            if(!is_array($list)||!array_is_list($list)||count($list)!==$expected)
                throw new \DomainException('Research record count mismatch: '.$kind);
            foreach($list as $item){
                if(!is_array($item))throw new \DomainException('Invalid company research record');
                $name=$kind==='new_companies'?($item['japanese']??null):($item['name']??null);
                if(!is_string($name)||trim($name)===''||strlen($name)>720)
                    throw new \DomainException('Missing or overlong company label');
                $id=$kind.'|'.$name;
                if(isset($seen[$id]))throw new \DomainException('Duplicate record');
                $seen[$id]=true;
                $priority=$item['priority']??null;
                if(!in_array($priority,self::PRIORITIES,true))throw new \DomainException('Unexpected research priority');
                $status=$item['status']??null;
                if(!is_string($status)||trim($status)==='')throw new \DomainException('Missing research status');
                $source=self::https((string)($item['url']??''));
                $excluded=$priority==='X';
                // Copy source claims without synthesizing unknown hiring conditions.
                // These are private reviewer notes, NEVER public vacancies.
                $claims=[];
                foreach(['name','japanese','area','role','type','graduates','foreign','age',
                         'salary','deadline','strength','risk','change','watch'] as $field){
                    if(!array_key_exists($field,$item))continue;
                    $value=$item[$field];
                    if(!is_string($value)||strlen($value)>8000)
                        throw new \DomainException('Invalid bounded research claim: '.$field);
                    $claims[$field]=$value;
                }
                $entries[]=[
                    'review_id'=>'r-'.substr(hash('sha256',self::VERSION.'|'.$id),0,24),
                    'kind'=>$kind==='new_companies'?'new_candidate':'existing_recheck',
                    'name'=>$name,
                    'priority'=>$priority,
                    'research_status'=>$status,
                    'source_claims'=>$claims,
                    'source_url'=>$source,
                    'source_checked_on'=>self::DATE,
                    'research_version'=>self::VERSION,
                    'raw_record_sha256'=>hash('sha256',json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                    'workflow_state'=>$excluded?'EXCLUDED_FROM_TOKYO_CANDIDATES':'PENDING_MANUAL_REVIEW',
                    'job_vacancy_confirmed'=>false,
                    'applicant_eligibility_confirmed'=>false,
                    'requires_manual_seed_identity_match'=>true,
                    'may_autopublish'=>false,
                    'may_notify_users'=>false,
                ];
                $seq++;
            }
        }
        return [
            'format'=>'kmm_research_delta_review_only_v1',
            'research_version'=>self::VERSION,
            'site_target_version'=>'0.1.26',
            'source_date'=>self::DATE,
            'baseline_version'=>'d1.0.0',
            'manifest_sha256'=>hash('sha256',$manifestBytes),
            'updates_sha256'=>hash('sha256',$updatesBytes),
            'report_sha256'=>hash('sha256',$reportBytes),
            'source_manifest_url'=>'https://drive.google.com/file/d/1GFtkH89LhwloKDOlC48Qj8c18dqLmkw0/view',
            'source_updates_url'=>$known['COMPANIES_UPDATES_d1.0.1.json']['drive_url'],
            'source_report_url'=>$known['企業再調査_新規候補_d1.0.1_2026-11-19.md']['drive_url'],
            'counts'=>['new_candidates'=>14,'existing_rechecks'=>13,'total'=>$seq,
                'excluded'=>count(array_filter($entries,static fn($x)=>$x['workflow_state']==='EXCLUDED_FROM_TOKYO_CANDIDATES'))],
            'review_required'=>true,
            'database_written'=>false,
            'published'=>false,
            'notification_queued'=>false,
            'requires_individual_vacancy_and_visa_verification'=>true,
            'note'=>'Research listing is not proof of remaining vacancies, eligibility, Tokyo placement, or immigration status. No company is publicly promoted by this review.',
            'entries'=>$entries,
        ];
    }
    private static function decode(string $raw):array {
        if(strlen($raw)<2||strlen($raw)>2*1024*1024)throw new \DomainException('Invalid source byte length');
        try{$value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \DomainException('Malformed source JSON',0,$e);}
        if(!is_array($value)||array_is_list($value))throw new \DomainException('JSON object expected');
        return $value;
    }
    private static function https(string $url):string {
        $v=parse_url($url);
        if(!is_array($v)||strtolower($v['scheme']??'')!=='https'||empty($v['host'])
            ||isset($v['user'])||isset($v['pass'])||strlen($url)>2048)
            throw new \DomainException('Unsafe research URL');
        return $url;
    }
}
