<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Filters and ranks the bounded directory, retaining unknown source values as unknown.
 * All R06 values come from already-installed, private, immutable source JSON.
 * No live route lookups, geocoding or opening-status inference is performed.
 */
final class BusinessDirectoryFilters {
    private const VERSION='research-d1.0.4-R06';
    private const EXTRA=['employment_type','salary_min','salary_max','selection_rounds','hub','commute_max'];

    public static function apply(PDO $db,string $type,array $rows,array $filters):array {
        if(!in_array($type,['companies','jobs'],true))throw new \InvalidArgumentException('Unknown directory');
        if(!$rows)return [];
        $authenticated=Identity::current()!==null;
        $metadata=self::publicJobs($db);
        if($authenticated)$metadata=array_replace($metadata,self::privateJobs($db));
        $byCompany=[];
        foreach($metadata as $job){
            $companyId=$job['company_id'];
            $byCompany[$companyId][]=$job;
        }
        $hasAdvanced=false;
        foreach(self::EXTRA as $field){if(isset($filters[$field])){$hasAdvanced=true;break;}}
        // Existing category/recruitment filters also must describe the same
        // posting when filtering a company with several different jobs.
        if($type==='companies' && (isset($filters['category'])||isset($filters['recruitment_state'])))$hasAdvanced=true;
        $publicTokyo=self::publicTokyoCompanies($db);
        $output=[];
        foreach($rows as $row){
            $id=(string)$row['id'];
            $candidates=$type==='jobs'?($metadata[$id]??null):($byCompany[$id]??[]);
            if($type==='jobs')$candidates=$candidates===null?[]:[$candidates];
            if($hasAdvanced){
                // A single actual posting must satisfy every selected employment,
                // salary, stage and commute condition; cross-posting joins are prohibited.
                $candidates=array_values(array_filter($candidates,static fn(array $item):bool=>self::match($item,$filters)));
                if(!$candidates)continue;
            }elseif(isset($filters['hub']) && $filters['hub']!=='any'){
                $candidates=array_values(array_filter($candidates,static fn(array $item):bool=>($item['hub']??null)===$filters['hub']));
            }
            $chosen=null;
            foreach($candidates as $candidate){
                if($chosen===null || self::compareRank($candidate,$chosen)<0)$chosen=$candidate;
            }
            $name=$type==='companies'?($row['display_name']??''):($row['title']??'');
            $row['_directory_sort']=[
                'tokyo'=>($chosen!==null && (bool)$chosen['tokyo']) || isset($publicTokyo[$type==='companies'?$id:(string)$row['company_id']]),
                'regular'=>$chosen!==null && $chosen['employment']==='正社員',
                'travel'=>$chosen['travel_sort']??null,
                'name'=>is_string($name)?$name:'',
                'id'=>$id,
            ];
            $output[]=$row;
        }
        usort($output,static function(array $a,array $b):int{
            $a=$a['_directory_sort'];$b=$b['_directory_sort'];
            if($a['tokyo']!==$b['tokyo'])return $a['tokyo']?-1:1;
            if($a['regular']!==$b['regular'])return $a['regular']?-1:1;
            if(($a['travel']===null)!==($b['travel']===null))return $a['travel']===null?1:-1;
            if($a['travel']!==null && $b['travel']!==null && $a['travel']!==$b['travel'])return $a['travel']<=>$b['travel'];
            return strcmp($a['name'],$b['name']) ?: strcmp($a['id'],$b['id']);
        });
        foreach($output as &$row)unset($row['_directory_sort']);unset($row);
        return $output;
    }

    private static function publicJobs(PDO $db):array {
        // Public jobs have no R06 wage, interview count or transit metadata.
        // Their existing category/recruitment metadata still supports same-job
        // company filtering, while unknown advanced fields never match.
        $sql="SELECT j.id,j.company_id,j.category,j.recruitment_state FROM jobs j
            JOIN companies co ON co.id=j.company_id AND co.visibility='published'
            WHERE j.archived_at IS NULL AND NOT EXISTS
              (SELECT 1 FROM seed_entity_states st WHERE st.record_type='jobs'
               AND st.record_id=j.id AND st.seed_version='research-d1.0.4-R06')
            LIMIT 5001";
        $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000)throw new \RuntimeException('Public job directory index exceeds 5000 rows');
        $result=[];
        foreach($rows as $job){
            $result[(string)$job['id']]=[
                'company_id'=>(string)$job['company_id'],
                'employment'=>null,'salary'=>null,'rounds'=>null,
                'hub'=>null,'total'=>null,'rail'=>null,'travel_sort'=>null,
                'tokyo'=>false,
                'category'=>$job['category'],'recruitment_state'=>$job['recruitment_state'],
            ];
        }
        return $result;
    }

    private static function privateJobs(PDO $db):array {
        // Fetch only the R06 rows whose source data is private to logged-in users.
        $sql="SELECT st.record_id,st.base_json,j.company_id,j.category,j.recruitment_state FROM seed_entity_states st
            JOIN jobs j ON j.id=st.record_id AND j.archived_at IS NULL
            JOIN companies co ON co.id=j.company_id AND co.visibility<>'archived'
            WHERE st.record_type='jobs' AND st.seed_version='research-d1.0.4-R06'
              AND EXISTS (SELECT 1 FROM seed_entity_states c6 WHERE c6.record_type='companies'
                          AND c6.record_id=co.id AND c6.seed_version='research-d1.0.4-R06')
            LIMIT 5001";
        $raw=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if(count($raw)>5000)throw new \RuntimeException('Private directory index exceeds 5000 rows');
        $result=[];
        foreach($raw as $item){
            $json=$item['base_json']??null;
            if(!is_string($json)||strlen($json)>200000)continue;
            try {$record=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){continue;}
            if(!is_array($record)||($record['source_release']??null)!=='d1.0.4 R06')continue;
            $commute=$record['commute_source_record']??[];
            if(!is_array($commute))$commute=[];
            $hub=self::value($commute['hub']??null);
            $total=self::minutes($commute['total_minutes']??null);
            $rail=self::minutes($commute['rail_minutes_min']??null);
            // A rail-only reference can assist approximate ordering, but never
            // qualifies for the total-commute filter.
            $employment=self::value($record['employment_type']??null);
            $src=$record['source_record']??[];
            $work=is_array($src)?self::value($src['worksite']??null):null;
            $result[(string)$item['record_id']]=[
                'company_id'=>(string)$item['company_id'],
                'employment'=>$employment,
                'category'=>is_string($item['category']??null)?$item['category']:null,
                'recruitment_state'=>is_string($item['recruitment_state']??null)?$item['recruitment_state']:null,
                'salary'=>PrivateR06View::primaryMonthlyAmount($record),
                'rounds'=>self::minutes($record['selection_round_count']??null),
                'hub'=>$hub,
                'total'=>$total,
                'rail'=>$rail,
                'travel_sort'=>$total??$rail,
                'tokyo'=>$work!==null && (str_contains($work,'東京都')||str_contains($work,'東京・')||str_contains($work,'東京23区'))
                    && !preg_match('/未確定|未確認|全国|地方|他地域/u',$work),
            ];
        }
        return $result;
    }

    private static function match(array $job,array $filters):bool {
        if(isset($filters['category']) && ($job['category']??null)!==$filters['category'])return false;
        if(isset($filters['recruitment_state']) && ($job['recruitment_state']??null)!==$filters['recruitment_state'])return false;
        if(isset($filters['employment_type']) && $job['employment']!==$filters['employment_type'])return false;
        $salary=$job['salary'];
        if(isset($filters['salary_min']) && ($salary===null||$salary<(int)$filters['salary_min']))return false;
        if(isset($filters['salary_max']) && ($salary===null||$salary>(int)$filters['salary_max']))return false;
        if(isset($filters['selection_rounds']) && ($job['rounds']===null||$job['rounds']!==(int)$filters['selection_rounds']))return false;
        if(isset($filters['hub']) && ($job['hub']===null || ($filters['hub']!=='any' && $job['hub']!==$filters['hub'])))return false;
        if(isset($filters['commute_max']) && ($job['hub']===null || $job['total']===null || $job['total']>(int)$filters['commute_max']))return false;
        return true;
    }

    private static function compareRank(array $a,array $b):int {
        if($a['tokyo']!==$b['tokyo'])return $a['tokyo']?-1:1;
        if(($a['employment']==='正社員')!==($b['employment']==='正社員'))return $a['employment']==='正社員'?-1:1;
        if(($a['travel_sort']===null)!==($b['travel_sort']===null))return $a['travel_sort']===null?1:-1;
        return ($a['travel_sort']??PHP_INT_MAX)<=>($b['travel_sort']??PHP_INT_MAX);
    }
    private static function minutes(mixed $value):?int {
        return is_int($value) && $value>=0 && $value<=10000?$value:null;
    }
    private static function value(mixed $value):?string {
        return is_string($value)&&trim($value)!==''?trim($value):null;
    }
    private static function publicTokyoCompanies(PDO $db):array {
        $stmt=$db->query("SELECT DISTINCT l.company_id FROM company_locations l
            JOIN companies c ON c.id=l.company_id
            WHERE l.archived_at IS NULL AND l.verification_state<>'private'
              AND c.visibility='published' AND l.address LIKE '東京都%' LIMIT 5001");
        $ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)>5000)throw new \RuntimeException('Location directory index exceeds 5000 rows');
        return array_fill_keys($ids,true);
    }
}
