<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Every write and read is scoped to the authenticated account, never a supplied user id. */
final class WorkspaceRepository {
    public const GUIDE_STEPS=['account','company_search','job_search','recruitment_timeline','calendar','map','library','applications','favorite','messages'];
    public static function normalizeFilters(array $raw):array {
        $out=[];
        foreach(['q'=>100,'region'=>80,'category'=>40,'recruitment_state'=>40] as $key=>$limit){
            $value=$raw[$key]??'';
            if(!is_string($value))throw new \InvalidArgumentException('Invalid filter');
            $value=trim($value);
            if(strlen($value)>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Filter exceeds allowed length');
            if($value==='')continue;
            if($key==='category'&&!in_array($value,['pastry','bread','research','product_development','quality_control','other'],true))throw new \InvalidArgumentException('Unknown category');
            if($key==='recruitment_state'&&!in_array($value,['confirmed_open','listed','closed','unverified'],true))throw new \InvalidArgumentException('Unknown recruitment state');
            $out[$key]=$value;
        }
        // Advanced directory filters: no unchecked SQL fragments or free-form numeric ranges.
        foreach(['employment_type'=>['正社員','契約社員','種類不明'], 'hub'=>['any','新宿','渋谷','池袋','上野・浅草','錦糸町','大崎','臨海']] as $key=>$allowed){
            $value=$raw[$key]??'';
            if(!is_string($value) || strlen($value)>32)throw new \InvalidArgumentException('Invalid directory filter');
            if($value!=='' && !in_array($value,$allowed,true))throw new \InvalidArgumentException('Unknown directory filter');
            if($value!=='')$out[$key]=$value;
        }
        foreach(['salary_min'=>[0,2000000,5000],'salary_max'=>[0,2000000,5000],'selection_rounds'=>[1,20,1],'commute_max'=>[1,240,1]] as $key=>$bounds){
            $value=$raw[$key]??'';
            if(!is_string($value) && !is_int($value))throw new \InvalidArgumentException('Invalid numeric directory filter');
            $value=(string)$value;
            if($value==='')continue;
            if(strlen($value)>8 || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1)throw new \InvalidArgumentException('Invalid numeric directory filter');
            $num=(int)$value;
            if($num<$bounds[0] || $num>$bounds[1] || $num%$bounds[2]!==0)throw new \InvalidArgumentException('Directory filter outside allowed bounds');
            $out[$key]=(string)$num;
        }
        // R07 research filters are real directory criteria, not presentation-only flags.
        // Keep them through pagination and saved searches; reject arbitrary values.
        foreach(['r07_tokyo','r07_hub30','r07_salary20','r07_age_any','r07_school_any'] as $key){
            if(!array_key_exists($key,$raw))continue;
            if($raw[$key]!=='1')throw new \InvalidArgumentException('Invalid R07 directory filter');
            $out[$key]='1';
        }
        if(isset($out['salary_min'],$out['salary_max']) && (int)$out['salary_min']>(int)$out['salary_max'])throw new \InvalidArgumentException('Invalid salary range');
        return $out;
    }
    public static function publicCompany(PDO $db,string $id):void {
        $id=BusinessData::id($id);
        if(BusinessRepository::read($db,'companies',$id,true)!==null)return;
        if(Identity::current()!==null){
            $q=$db->prepare("SELECT 1 FROM companies c JOIN seed_entity_states s ON s.record_id=c.id AND s.record_type='companies' AND s.seed_version='research-d1.0.4-R06' WHERE c.id=? AND c.visibility='draft' LIMIT 1");
            $q->execute([$id]);if($q->fetchColumn())return;
        }
        throw new \InvalidArgumentException('Company unavailable');
    }
    public static function favoriteIds(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT company_id FROM user_favorite_companies WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $q->execute([$uid]);return array_column($q->fetchAll(PDO::FETCH_ASSOC),'company_id');
    }
    public static function favorites(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT f.company_id,c.display_name,c.visibility,c.description FROM user_favorite_companies f LEFT JOIN companies c ON c.id=f.company_id WHERE f.user_id=? ORDER BY f.created_at DESC LIMIT 100');
        $q->execute([$uid]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function setFavorite(PDO $db,int $uid,string $companyId,bool $add):void {
        $companyId=BusinessData::id($companyId);
        if($add){self::publicCompany($db,$companyId);$q=$db->prepare('INSERT IGNORE INTO user_favorite_companies (user_id,company_id) VALUES (?,?)');}
        else $q=$db->prepare('DELETE FROM user_favorite_companies WHERE user_id=? AND company_id=?');
        $q->execute([$uid,$companyId]);
    }

    /** Reserved per-user JSON row: job favorites and uninstalled R07 company favorites.
     * Keeps Schema41 unchanged while retaining legacy favorite-company rows. */
    public const FAVORITE_LABEL='__kmm_favorite_items_v1__';
    public static function favoriteStored(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT filters_json FROM user_saved_filters WHERE user_id=? AND label=? ORDER BY created_at DESC LIMIT 1');
        $q->execute([$uid,self::FAVORITE_LABEL]);$raw=$q->fetchColumn();
        $empty=['companies'=>[],'jobs'=>[]];if(!is_string($raw))return $empty;
        try{$parsed=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){return $empty;}
        if(!is_array($parsed))return $empty;
        foreach(['companies','jobs'] as $kind){
            $ids=$parsed[$kind]??[];if(!is_array($ids))continue;
            foreach(array_slice($ids,0,300) as $id)if(is_string($id)&&self::validFavoriteId($kind,$id))$empty[$kind][$id]=true;
            $empty[$kind]=array_keys($empty[$kind]);
        }
        return $empty;
    }
    public static function validFavoriteId(string $kind,string $id):bool {
        return in_array($kind,['companies','jobs'],true)
            && (preg_match('/\A[a-f0-9]{32}\z/D',$id)===1 || ($kind==='companies'?ResearchR07::isCompanyId($id):ResearchR07::isJobId($id)));
    }
    public static function favoriteKeys(PDO $db,int $uid,string $kind):array {
        $ids=self::favoriteStored($db,$uid)[$kind]??[];
        if($kind==='companies')$ids=array_merge($ids,self::favoriteIds($db,$uid));
        return array_values(array_unique($ids));
    }
    public static function setFavoriteEntity(PDO $db,int $uid,string $kind,string $id,bool $add):void {
        if(!self::validFavoriteId($kind,$id))throw new \InvalidArgumentException('お気に入りの対象が正しくありません。');
        // Preserve existing user_favorite_companies behavior for installed company IDs.
        if($kind==='companies' && preg_match('/\A[a-f0-9]{32}\z/D',$id)){
            if($add && BusinessRepository::read($db,'companies',$id,true)===null
                && (Identity::current()===null || PrivateR06View::snapshot($db,'companies',$id)===null))
                throw new \InvalidArgumentException('企業情報を確認できません。');
            if($add){$q=$db->prepare('INSERT IGNORE INTO user_favorite_companies(user_id,company_id) VALUES(?,?)');$q->execute([$uid,$id]);}
            else {$q=$db->prepare('DELETE FROM user_favorite_companies WHERE user_id=? AND company_id=?');$q->execute([$uid,$id]);}
            return;
        }
        if(preg_match('/\A[a-f0-9]{32}\z/D',$id)){
            if($kind==='jobs' && BusinessRepository::read($db,'jobs',$id,false)===null)throw new \InvalidArgumentException('求人が見つかりません。');
        }elseif(($kind==='jobs'?ResearchR07::job($id):ResearchR07::company($id))===null){throw new \InvalidArgumentException('調査対象が見つかりません。');}
        $db->beginTransaction();
        try {
            // Lock the account row; concurrent favorite writes from two tabs cannot overwrite each other.
            $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);
            if(!$lock->fetchColumn())throw new \InvalidArgumentException('アカウントを確認できません。');
            $current=self::favoriteStored($db,$uid);$map=array_fill_keys($current[$kind],true);
            if($add){if(count($map)>=300&&!isset($map[$id]))throw new \InvalidArgumentException('お気に入りは300件までです。');$map[$id]=true;}
            else unset($map[$id]);
            $current[$kind]=array_keys($map);
            $del=$db->prepare('DELETE FROM user_saved_filters WHERE user_id=? AND label=?');$del->execute([$uid,self::FAVORITE_LABEL]);
            if($current['companies']!==[] || $current['jobs']!==[]){$q=$db->prepare('INSERT INTO user_saved_filters(id,user_id,label,filters_json) VALUES(?,?,?,?)');$q->execute([BusinessData::id(),$uid,self::FAVORITE_LABEL,json_encode($current,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function note(PDO $db,int $uid,string $companyId):string {
        $q=$db->prepare('SELECT note FROM user_company_notes WHERE user_id=? AND company_id=? LIMIT 1');$q->execute([$uid,BusinessData::id($companyId)]);
        return (string)($q->fetchColumn()?:'');
    }
    public static function notes(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT n.company_id,n.note,c.display_name,c.visibility FROM user_company_notes n LEFT JOIN companies c ON c.id=n.company_id WHERE n.user_id=? ORDER BY n.updated_at DESC LIMIT 100');
        $q->execute([$uid]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function setNote(PDO $db,int $uid,string $companyId,mixed $note):void {
        $companyId=BusinessData::id($companyId);
        if(!is_string($note)||strlen($note)>3000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$note))throw new \InvalidArgumentException('Invalid private note');
        if(trim($note)===''){$q=$db->prepare('DELETE FROM user_company_notes WHERE user_id=? AND company_id=?');$q->execute([$uid,$companyId]);return;}
        self::publicCompany($db,$companyId);
        $q=$db->prepare('INSERT INTO user_company_notes(user_id,company_id,note) VALUES(?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note),updated_at=UTC_TIMESTAMP()');
        $q->execute([$uid,$companyId,$note]);
    }
    public static function filters(PDO $db,int $uid):array {
        $q=$db->prepare("SELECT id,label,filters_json FROM user_saved_filters WHERE user_id=? AND label NOT IN ('__kmm_home_recommendation__','__kmm_favorite_items_v1__','__kmm_recent_views_v1__') ORDER BY created_at DESC LIMIT 20");
        $q->execute([$uid]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function saveFilter(PDO $db,int $uid,mixed $label,array $raw):void {
        $label=BusinessData::text($label,80,true);if(in_array($label,[ResearchRecommendation::SAVED_LABEL,self::FAVORITE_LABEL,ViewHistory::LABEL],true))throw new \InvalidArgumentException('Reserved saved filter label');$filters=self::normalizeFilters($raw);
        if(!$filters)throw new \InvalidArgumentException('Empty search filter');
        $q=$db->prepare("SELECT COUNT(*) FROM user_saved_filters WHERE user_id=? AND label NOT IN ('__kmm_home_recommendation__','__kmm_favorite_items_v1__','__kmm_recent_views_v1__')");$q->execute([$uid]);
        if((int)$q->fetchColumn()>=10)throw new \InvalidArgumentException('At most 10 saved searches');
        $q=$db->prepare('INSERT INTO user_saved_filters(id,user_id,label,filters_json) VALUES (?,?,?,?)');
        $q->execute([BusinessData::id(),$uid,$label,json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    public static function removeFilter(PDO $db,int $uid,string $filterId):void {
        $q=$db->prepare('DELETE FROM user_saved_filters WHERE id=? AND user_id=?');$q->execute([BusinessData::id($filterId),$uid]);
    }
    public static function guideSteps(PDO $db,int $uid):array {
        $q=$db->prepare('SELECT step_key FROM user_guide_steps WHERE user_id=?');$q->execute([$uid]);return array_column($q->fetchAll(PDO::FETCH_ASSOC),'step_key');
    }
    public static function setGuideStep(PDO $db,int $uid,string $step,bool $done):void {
        if(!in_array($step,self::GUIDE_STEPS,true))throw new \InvalidArgumentException('Unknown onboarding step');
        if($done)$q=$db->prepare('INSERT IGNORE INTO user_guide_steps(user_id,step_key) VALUES (?,?)');
        else $q=$db->prepare('DELETE FROM user_guide_steps WHERE user_id=? AND step_key=?');
        $q->execute([$uid,$step]);
    }
}
