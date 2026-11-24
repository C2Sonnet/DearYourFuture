<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** Whitelisted public directory search. Every input goes to parameters; SQL fragments are constants. */
final class BusinessSearch {
    public static function queryParts(string $type,array $filters):array {
        $filters=WorkspaceRepository::normalizeFilters($filters);
        if(!in_array($type,['companies','jobs'],true))throw new \InvalidArgumentException('Unknown directory');
        $conditions=[];$args=[];
        $company=$type==='companies'?'companies':'co';
        $private=Identity::current()!==null;
        $companyPublic="companies.visibility='published' AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='companies' AND r6.record_id=companies.id AND r6.seed_version='research-d1.0.4-R06')";
        $companyPrivate="companies.visibility<>'archived' AND EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='companies' AND r6.record_id=companies.id AND r6.seed_version='research-d1.0.4-R06')";
        $jobPublic="co.visibility='published' AND NOT EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='jobs' AND r6.record_id=jobs.id AND r6.seed_version='research-d1.0.4-R06')";
        $jobPrivate="co.visibility<>'archived' AND EXISTS (SELECT 1 FROM seed_entity_states r6 WHERE r6.record_type='jobs' AND r6.record_id=jobs.id AND r6.seed_version='research-d1.0.4-R06') AND EXISTS (SELECT 1 FROM seed_entity_states c6 WHERE c6.record_type='companies' AND c6.record_id=co.id AND c6.seed_version='research-d1.0.4-R06')";
        if($type==='companies')$conditions[]='('.$companyPublic.($private?' OR '.$companyPrivate:'').')';
        else {$conditions[]='jobs.archived_at IS NULL';$conditions[]='('.$jobPublic.($private?' OR '.$jobPrivate:'').')';}
        if(isset($filters['q'])){
            if($type==='companies')$conditions[]="(companies.display_name LIKE ? ESCAPE '!' OR companies.legal_name LIKE ? ESCAPE '!' OR companies.description LIKE ? ESCAPE '!')";
            else $conditions[]="(jobs.title LIKE ? ESCAPE '!' OR co.display_name LIKE ? ESCAPE '!')";
            $parts=$type==='companies'?3:2;
            for($i=0;$i<$parts;$i++)$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']).'%';
        }
        if(isset($filters['category'])){
            $conditions[]=$type==='companies'?"EXISTS (SELECT 1 FROM jobs j WHERE j.company_id=companies.id AND j.category=? AND j.archived_at IS NULL)":"jobs.category=?";
            $args[]=$filters['category'];
        }
        if(isset($filters['recruitment_state'])){
            $conditions[]=$type==='companies'?"EXISTS (SELECT 1 FROM jobs j WHERE j.company_id=companies.id AND j.recruitment_state=? AND j.archived_at IS NULL)":"jobs.recruitment_state=?";
            $args[]=$filters['recruitment_state'];
        }
        if(isset($filters['region'])){
            // Private R06 location rows are visible for authenticated directory queries only.
            $locationPrivacy=$private?"(l.verification_state<>'private' OR EXISTS (SELECT 1 FROM seed_entity_states rs WHERE rs.record_type='companies' AND rs.record_id=l.company_id AND rs.seed_version='research-d1.0.4-R06'))":"l.verification_state<>'private'";
            $conditions[]=$type==='companies'?"EXISTS (SELECT 1 FROM company_locations l WHERE l.company_id=companies.id AND l.archived_at IS NULL AND $locationPrivacy AND l.address LIKE ? ESCAPE '!')":"EXISTS (SELECT 1 FROM job_locations jl JOIN company_locations l ON l.id=jl.location_id WHERE jl.job_id=jobs.id AND l.archived_at IS NULL AND $locationPrivacy AND l.address LIKE ? ESCAPE '!')";
            $args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['region']).'%';
        }
        return [implode(' AND ',$conditions),$args];
    }
    public static function find(PDO $db,string $type,array $raw,int $limit=25,int $offset=0):array {
        if($limit<1||$limit>5000||$offset<0)throw new \InvalidArgumentException('Invalid pagination');
        [$where,$args]=self::queryParts($type,$raw);
        $from=$type==='companies'?'companies':'jobs JOIN companies co ON co.id=jobs.company_id';
        // Sorting and per-job R06 filters must happen before pagination. Fail closed
        // if the directory exceeds this bounded individual-use in-memory index.
        $order=$type==='companies'?'companies.display_name,companies.id':'jobs.created_at DESC,jobs.id';
        $stmt=$db->prepare('SELECT '.($type==='companies'?'companies.*':'jobs.*,co.display_name AS company_display_name').' FROM '.$from.' WHERE '.$where.' ORDER BY '.$order.' LIMIT 5001');
        $stmt->execute($args);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000)throw new \RuntimeException('Directory index exceeds the documented 5000-row bound');
        $all=BusinessDirectoryFilters::apply($db,$type,$rows,WorkspaceRepository::normalizeFilters($raw));
        if((Identity::current()!==null&&ResearchRecommendation::requestedFilters($raw)!==[])||isset($raw['role_class'])||isset($raw['application_class'])){
            $all=array_values(array_filter($all,static function(array $row)use($db,$type,$raw):bool{
                $src=ResearchR07::sourceId($db,$type,(string)$row['id']);
                $r=$src!==null?($type==='companies'?ResearchR07::company($src):ResearchR07::job($src)):null;
                if($r===null)return false;foreach($type==='companies'?ResearchClassification::companyJobs($src):[$r] as $job)if(ResearchClassification::matches($job,$raw)&&ResearchRecommendation::matches($db,'jobs',$job,$raw))return true;return false;
            }));
        }
        return array_slice($all,$offset,$limit);
    }
    public static function form(string $type,array $raw):string {
        $data=WorkspaceRepository::normalizeFilters($raw);$path=$type==='jobs'?'/jobs':'/companies';
        $h='<form method="get" action="'.$path.'" class="directory-filters" role="search"><fieldset><legend>基本情報・職種</legend><label>キーワード<input name="q" maxlength="100" value="'.Security::h($data['q']??'').'"></label><label>地域（住所に記載された地域名）<input name="region" maxlength="80" value="'.Security::h($data['region']??'').'"></label>';
        $h.='<label>専門職<select name="category"><option value="">指定しない</option>';
        foreach(['pastry'=>'製菓','bread'=>'製パン','research'=>'研究','product_development'=>'商品開発','quality_control'=>'品質管理','other'=>'その他'] as $value=>$label)$h.='<option value="'.$value.'"'.(($data['category']??'')===$value?' selected':'').'>'.$label.'</option>';
        $h.='</select></label><label>募集状態<select name="recruitment_state"><option value="">指定しない</option>';
        foreach(['confirmed_open'=>'確認日時点で受付','listed'=>'掲載あり・枠未確認','closed'=>'募集受付終了','unverified'=>'募集状態未確認'] as $value=>$label)$h.='<option value="'.$value.'"'.(($data['recruitment_state']??'')===$value?' selected':'').'>'.$label.'</option>';
        $h.='</select></label>';
        foreach(ResearchClassification::options() as $key=>$options){$h.='<label>'.($key==='role_class'?'専門技術との適合性分類':'応募確認候補の分類').'<select name="'.$key.'"><option value="">指定しない</option>';foreach($options as $v=>$label)$h.='<option value="'.Security::h($v).'"'.(($data[$key]??'')===$v?' selected':'').'>'.Security::h($label).'</option>';$h.='</select></label>'; }
        $h.='</fieldset><fieldset><legend>給与・雇用形態</legend>';
        foreach(['r07_salary20'=>'初任給月20万円以上'] as $k=>$label)$h.='<label><input type="checkbox" name="'.$k.'" value="1"'.(($data[$k]??'')==='1'?' checked':'').'>'.$label.'</label>';
        // These fields are also available on the company directory: the same job must
        // satisfy all advanced job filters for its company to appear.
        $h.='<label>雇用形態<select name="employment_type"><option value="">指定しない</option>';
        foreach(['正社員'=>'正社員','契約社員'=>'契約社員','種類不明'=>'種類不明（原典未確定）'] as $val=>$label)$h.='<option value="'.Security::h($val).'"'.(($data['employment_type']??'')===$val?' selected':'').'>'.$label.'</option>';
        $h.='</select></label>';
        foreach(['salary_min'=>'月給下限（5,000円刻み）','salary_max'=>'月給上限（5,000円刻み）'] as $field=>$label){
            $h.='<label>'.Security::h($label).'<input type="number" name="'.$field.'" min="0" max="2000000" step="5000" inputmode="numeric" value="'.Security::h($data[$field]??'').'"></label>';
        }
        $h.='</fieldset><fieldset><legend>応募条件・選考</legend>';
        foreach(['r07_age_any'=>'年齢不問','r07_school_any'=>'学歴不問'] as $k=>$label)$h.='<label><input type="checkbox" name="'.$k.'" value="1"'.(($data[$k]??'')==='1'?' checked':'').'>'.$label.'</label>';
        $h.='<label>選考回数（書類選考含む）<select name="selection_rounds"><option value="">指定しない</option>';
        for($i=1;$i<=20;$i++)$h.='<option value="'.$i.'"'.(($data['selection_rounds']??'')===(string)$i?' selected':'').'>'.$i.'回</option>';
        $h.='</select></label></fieldset><fieldset><legend>勤務地・通勤</legend><label>副都心<select name="hub"><option value="">指定しない</option>';
        foreach(['any'=>'任意（記録がある副都心）','新宿'=>'新宿','渋谷'=>'渋谷','池袋'=>'池袋','上野・浅草'=>'上野・浅草','錦糸町'=>'錦糸町','大崎'=>'大崎','臨海'=>'臨海'] as $val=>$label)
            $h.='<option value="'.Security::h($val).'"'.(($data['hub']??'')===$val?' selected':'').'>'.Security::h($label).'</option>';
        $h.='</select></label><label>副都心までの総通勤時間（x分以内）<input type="number" name="commute_max" min="1" max="240" step="1" inputmode="numeric" value="'.Security::h($data['commute_max']??'').'"></label>';
        $h.='<p class="note search-commute-note">通勤時間は徒歩などを含む記録済み総計だけで絞ります。駅間の電車時間だけの求人や未確認求人は一致扱いにしません。選考回数・月給も根拠がある求人のみ対象です。東京／正社員／記録済み所要時間が短い順に優先表示します。</p>';
        
        foreach(['r07_tokyo'=>'東京都内','r07_hub30'=>'任意副都心まで電車30分以内'] as $k=>$label)
            $h.='<label><input type="checkbox" name="'.$k.'" value="1"'.(($data[$k]??'')==='1'?' checked':'').'>'.$label.'</label>';
        $h.='</fieldset>';
        $h.='<button class="btn primary" type="submit">検索する</button><a class="btn ghost" href="'.$path.'">条件をクリア</a></form>';
        if($type==='companies'&&$data&&Identity::current()!==null){$h.='<form method="post" action="/my/filters" class="workspace-inline">'.Session::csrfField().'<label>検索条件を保存する名前<input name="label" maxlength="80" required></label>';
            foreach($data as $key=>$value)$h.='<input type="hidden" name="'.Security::h($key).'" value="'.Security::h($value).'">';
            $h.='<input type="hidden" name="action" value="save"><input type="hidden" name="return_to" value="/my"><button class="btn ghost">検索条件を保存</button></form>';
        }
        return $h;
    }
    public static function paginationUrl(string $path,array $raw,int $page):string {
        return $path.'?'.http_build_query([...WorkspaceRepository::normalizeFilters($raw),'page'=>$page]);
    }
}
