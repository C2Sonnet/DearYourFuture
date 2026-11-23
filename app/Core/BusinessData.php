<?php
declare(strict_types=1);
namespace KMM\Core;

/** Centralized allowlist for writeable business fields; untrusted form/seed values cannot become SQL identifiers. */
final class BusinessData {
    public const KINDS = ['companies','brands','sources','company_locations','jobs'];
    public const COMPANY_STATES = ['draft','published','archived'];
    public const RECRUITMENT = ['unverified','listed','confirmed_open','closed'];
    public const RECRUITMENT_LABELS = [
        'unverified'=>'募集状態未確認', 'listed'=>'募集掲載あり・残枠未確認',
        'confirmed_open'=>'確認日時点で受付中', 'closed'=>'募集受付終了',
    ];
    public const LOCATION_KIND_LABELS = [
        'headquarters'=>'本社', 'registered_office'=>'登記上の本店',
        'office'=>'事務所', 'factory'=>'工場', 'development'=>'研究・開発拠点',
        'shop'=>'店舗', 'other'=>'その他・未確認',
    ];
    public const VERIFICATION_LABELS = [
        'unconfirmed'=>'未確認', 'candidate'=>'候補（未確定）',
        'verified'=>'確認済み', 'private'=>'個人資料',
    ];
    public const ASSIGNMENT_LABELS = [
        'candidate'=>'配属候補（未確定）', 'confirmed'=>'確認済み配属先',
    ];
    public static function recruitmentLabel(?string $value):string {return self::RECRUITMENT_LABELS[$value??'unverified']??'未確認';}
    public static function verificationLabel(?string $value):string {return self::VERIFICATION_LABELS[$value??'unconfirmed']??'未確認';}
    public static function assignmentLabel(?string $value):string {return self::ASSIGNMENT_LABELS[$value??'candidate']??'未確認';}
    public const LOCATION_KINDS = ['headquarters','registered_office','office','factory','development','shop','other'];
    public const VERIFICATION = ['unconfirmed','candidate','verified','private'];
    public const SOURCE_TYPE_LABELS = ['company_official'=>'企業公式情報','recruitment_official'=>'公式採用情報','job_board'=>'求人掲載サイト','ir'=>'IR・開示情報','public_agency'=>'行政・公的機関','social_official'=>'公式SNS','community'=>'コミュニティ情報','research_snapshot'=>'調査時点の記録','other'=>'その他'];
    public static function sourceTypeLabel(?string $value):string {return self::SOURCE_TYPE_LABELS[$value??'other']??'不明';}
    public const SOURCE_TYPES = ['company_official','recruitment_official','job_board','ir','public_agency','social_official','community','research_snapshot','other'];
    public static function id(?string $value=null): string {
        if ($value===null) return bin2hex(random_bytes(16));
        if (!preg_match('/\A[a-f0-9]{32}\z/D',$value)) throw new \InvalidArgumentException('Invalid record ID');
        return $value;
    }
    public static function text(mixed $raw,int $limit=255,bool $required=false): ?string {
        if(!is_string($raw))throw new \InvalidArgumentException('Invalid text');
        $value=trim($raw);
        if($value==='' && !$required)return null;
        if($value==='' || strlen($value)>$limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new \InvalidArgumentException('Invalid or oversized text');
        return $value;
    }
    public static function select(mixed $raw,array $allowed): string {
        if(!is_string($raw)||!in_array($raw,$allowed,true))throw new \InvalidArgumentException('Invalid selection');
        return $raw;
    }
    public static function optionalDate(mixed $raw): ?string {
        $value=self::text($raw,10);
        if($value===null)return null;
        if(!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new \InvalidArgumentException('Invalid calendar date');
        return $value;
    }
    public static function url(mixed $raw): ?string {
        $value=self::text($raw,2000);
        if($value===null)return null;
        if(!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true))throw new \InvalidArgumentException('Invalid HTTP URL');
        if(parse_url($value,PHP_URL_USER)!==null||parse_url($value,PHP_URL_PASS)!==null)throw new \InvalidArgumentException('Credentials in URL are prohibited');
        return $value;
    }
    public static function fields(string $kind,array $input): array {
        if(!in_array($kind,self::KINDS,true))throw new \InvalidArgumentException('Unsupported business type');
        return match($kind){
            'companies'=>[
                'display_name'=>self::text($input['display_name']??null,240,true),
                'legal_name'=>self::text($input['legal_name']??'',240),
                'description'=>self::text($input['description']??'',6000),
                'website_url'=>self::url($input['website_url']??''),
                'visibility'=>self::select($input['visibility']??'draft',self::COMPANY_STATES),
            ],
            'brands'=>['name'=>self::text($input['name']??null,240,true)],
            'sources'=>[
                'title'=>self::text($input['title']??null,500,true),
                'source_url'=>self::url($input['source_url']??''),
                'source_type'=>self::select($input['source_type']??'other',self::SOURCE_TYPES),
                'publication_state'=>self::select($input['publication_state']??'private',['private','public']),
                'observed_on'=>self::optionalDate($input['observed_on']??''),
                'note'=>self::text($input['note']??'',2000),
            ],
            'company_locations'=>[
                'name'=>self::text($input['name']??null,240,true),
                'kind'=>self::select($input['kind']??'other',self::LOCATION_KINDS),
                'address'=>self::text($input['address']??'',800),
                'verification_state'=>self::select($input['verification_state']??'unconfirmed',self::VERIFICATION),
                'source_id'=>self::nullableId($input['source_id']??''),
                'latitude'=>self::coordinate($input['latitude']??'',-90,90),
                'longitude'=>self::coordinate($input['longitude']??'',-180,180),
                'verified_on'=>self::optionalDate($input['verified_on']??''),
                'note'=>self::text($input['note']??'',2000),
            ],
            'jobs'=>[
                'title'=>self::text($input['title']??null,240,true),
                'category'=>self::select($input['category']??'other',['pastry','bread','research','product_development','quality_control','other']),
                'graduate_year'=>self::year($input['graduate_year']??2027),
                'recruitment_state'=>self::select($input['recruitment_state']??'unverified',self::RECRUITMENT),
                'source_id'=>self::nullableId($input['source_id']??''),
                'observed_on'=>self::optionalDate($input['observed_on']??''),
                'deadline_on'=>self::optionalDate($input['deadline_on']??''),
                'note'=>self::text($input['note']??'',3000),
            ],
        };
    }
    private static function year(mixed $raw): int {
        if(!is_int($raw) && (!is_string($raw)||!preg_match('/\A\d{4}\z/D',$raw)))throw new \InvalidArgumentException('Invalid recruitment year');
        $year=(int)$raw;
        if($year<2020||$year>2100)throw new \InvalidArgumentException('Recruitment year out of range');
        return $year;
    }
    private static function coordinate(mixed $raw,int $low,int $high): ?string {
        if($raw===''||$raw===null)return null;
        if(!is_string($raw)&&!is_int($raw)&&!is_float($raw))throw new \InvalidArgumentException('Invalid coordinates');
        $value=(string)$raw;
        if(!preg_match('/\A-?\d{1,3}(?:\.\d{1,6})?\z/D',$value)||!is_finite((float)$value) || (float)$value<$low || (float)$value>$high)throw new \InvalidArgumentException('Invalid coordinates');
        return $value;
    }
    private static function nullableId(mixed $value): ?string {
        if($value===''||$value===null)return null;
        if(!is_string($value))throw new \InvalidArgumentException('Invalid source ID');
        return self::id($value);
    }
    public static function requireProvenance(string $kind,array $data): void {
        if($kind==='sources' && $data['publication_state']==='public' && $data['source_type']==='research_snapshot')throw new \InvalidArgumentException('Research snapshots are restricted to admin use');
        if($kind==='company_locations'){
            if(($data['latitude']===null)!==($data['longitude']===null))throw new \InvalidArgumentException('Latitude and longitude must be supplied together');
            if($data['verification_state']!=='verified' && $data['latitude']!==null)throw new \InvalidArgumentException('Unverified locations must not carry map pins');
            if($data['verification_state']==='verified' && ($data['source_id']===null||$data['verified_on']===null))throw new \InvalidArgumentException('Verified location needs provenance and date');
        }
        if($kind==='jobs' && $data['recruitment_state']==='confirmed_open' && ($data['source_id']===null||$data['observed_on']===null)){
            throw new \InvalidArgumentException('Confirmed recruitment requires a source and checked date');
        }
    }
}
