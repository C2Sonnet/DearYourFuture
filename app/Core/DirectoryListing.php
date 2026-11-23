<?php
 declare(strict_types=1);
 namespace KMM\Core;
 use PDO;
 /** One directory, with R06 canonical DB and R07-only snapshot records combined. */
 final class DirectoryListing {
     private const PER_PAGE=20;
     public static function all(PDO $db,string $kind,array $filters):array {
         $filters=WorkspaceRepository::normalizeFilters($filters);
         // DB-only/user-authored rows follow their own editable metadata and search rules.
         $matchedDb=BusinessSearch::find($db,$kind,$filters,5000,0);
         $matchingIds=array_fill_keys(array_map(static fn(array $x):string=>(string)$x['id'],$matchedDb),true);
         $allDb=BusinessSearch::find($db,$kind,[],5000,0);
         $items=[];$used=[];
         foreach($allDb as $row){
             $dbId=(string)$row['id'];
             $source=ResearchR07::sourceId($db,$kind,$dbId);
             if($source!==null){
                 if(ResearchR07Scope::excluded($kind,$source))continue;
                 $current=$kind==='companies'?ResearchR07::company($source):ResearchR07::job($source);
                 // Preserve newly edited/user-owned DB records; do not falsely mark unclassified history as removed.
                 if($current!==null){
                     if(!self::matchSnapshot($db,$kind,$current,$filters))continue;
                 }elseif(!isset($matchingIds[$dbId]))continue;
             }elseif(!isset($matchingIds[$dbId]))continue;
             $items[]=['id'=>$dbId,'origin'=>'db','row'=>$row];$used[$dbId]=true;
         }
         if(Identity::current()===null)return $items;
         $r07=$kind==='companies'?ResearchR07::companies():ResearchR07::jobs();
         foreach($r07 as $id=>$record){
             $dbId=ResearchR07::dbId($db,$kind,$id);
             if($dbId!==null&&isset($used[$dbId]))continue;
             if($dbId!==null)continue; // R06 record exists but is private/hidden in DB; do not bypass access controls.
             if(!self::matchSnapshot($db,$kind,$record,$filters))continue;
             $items[]=['id'=>$id,'origin'=>'research','row'=>$record];
         }
         return $items;
     }
     private static function matchSnapshot(PDO $db,string $kind,array $record,array $f):bool {
         $company=$kind==='companies'?$record:ResearchR07::company((string)($record['company_id']??''));
         if($company===null)return false;
         $name=$kind==='companies'?ResearchR07::displayName($record):ResearchR07::jobTitle($record);
         $companyName=ResearchR07::displayName($company);
         $work=$kind==='companies'?ResearchR07::address($company):ResearchR07::firstValue($record['worksite']??null);
         if(isset($f['q'])&&mb_stripos($name.' '.$companyName.' '.(string)$work,$f['q'])===false)return false;
         if(isset($f['region'])&&($work===null||mb_stripos($work,$f['region'])===false))return false;
         if(!ResearchRecommendation::matches($db,$kind,$record,$f))return false;
         $jobs=$kind==='companies'?ResearchR07::companyJobs((string)($record['company_id']??'')):[$record];
         $criteria=array_intersect_key($f,array_flip(['category','recruitment_state','employment_type','salary_min','salary_max','selection_rounds','hub','commute_max']));
         if($criteria===[])return true;
         foreach($jobs as $job)if(self::matchJob($job,$criteria))return true;
         return false;
     }
     private static function matchJob(array $job,array $f):bool {
         $title=ResearchR07::jobTitle($job);
         if(isset($f['category'])){
             $regex=match($f['category']){
                'pastry'=>'/製菓|菓子|パティシエ|ケーキ/u','bread'=>'/製パン|パン|ベーカリー/u',
                'research'=>'/研究|分析/u','product_development'=>'/商品開発|製品開発/u',
                'quality_control'=>'/品質管理|品質保証/u',default=>null};
             if($regex===null||preg_match($regex,$title)!==1)return false;
         }
         $type=ResearchR07::firstValue($job['employment_type']??null);
         if(isset($f['employment_type'])&&$type!==$f['employment_type'])return false;
         // No inferred open status from a published link. Only missing status is unknown.
         if(isset($f['recruitment_state'])){
             $r=$job['recheck_r07']??[];$state=is_array($r)?ResearchR07::firstValue($r['recruitment_status']??null):null;
             if($f['recruitment_state']!=='unverified'||($state!==null&&!preg_match('/未確認|不明/u',$state)))return false;
         }
         $rounds=$job['selection_round_count']??($job['recheck_r07']['selection_round_count']??null);
         if(isset($f['selection_rounds'])&&(!is_numeric($rounds)||(int)$rounds!==(int)$f['selection_rounds']))return false;
         $c=$job['commute']??[];if(!is_array($c))$c=[];
         $hub=ResearchR07::firstValue($c['hub']??null);
         if(isset($f['hub'])&&($hub===null||($f['hub']!=='any'&&$hub!==$f['hub'])))return false;
         if(isset($f['commute_max'])&&(!is_numeric($c['total_minutes']??null)||$hub===null||(float)$c['total_minutes']>(int)$f['commute_max']))return false;
         if(isset($f['salary_min'])||isset($f['salary_max'])){
             $b6=$job['r07_batch6_research']??[];
             $amount=(is_array($b6)&&($b6['full_job_course_publicly_readable']??false)===true)?($b6['salary_jpy_specialist']??null):null;
             if(!is_int($amount))return false;
             if(isset($f['salary_min'])&&$amount<(int)$f['salary_min'])return false;
             if(isset($f['salary_max'])&&$amount>(int)$f['salary_max'])return false;
         }
         return true;
     }
     public static function summary(int $total,int $page):string {
         $start=$total?($page-1)*self::PER_PAGE+1:0;$end=min($total,$page*self::PER_PAGE);
         return '結果数：'.$total.' 件｜今は第 '.$start.' 件 ～ 第 '.$end.' 件';
     }
     public static function pages(array $all,int $requested):array {
         $total=count($all);$last=max(1,(int)ceil($total/self::PER_PAGE));$page=max(1,min($requested,$last));
         return [$page,$last,array_slice($all,($page-1)*self::PER_PAGE,self::PER_PAGE),$total];
     }
     public static function pagination(string $path,array $filters,int $page,int $last,int $total):string {
         $summary=self::summary($total,$page);
         $html='<div class="directory-pager"><p class="directory-page-info">'.Security::h($summary).'</p><nav class="directory-pages" aria-label="ページ切り替え">';
         $url=static fn(int $p):string=>Security::h(BusinessSearch::paginationUrl($path,$filters,$p));
         if($page>1)$html.='<a class="btn ghost" href="'.$url($page-1).'">前へ</a>';
         $visible=[];
         foreach([1,2,$last-1,$last,$page-1,$page,$page+1] as $n)if($n>=1&&$n<=$last)$visible[$n]=true;
         ksort($visible);$prev=0;
         foreach(array_keys($visible) as $n){
             if($prev&&$n>$prev+1){
                 $html.='<details class="directory-ellipsis"><summary class="btn ghost" aria-label="'.($prev+1).'から'.($n-1).'ページへ移動">...</summary><div class="directory-ellipsis-menu">';
                 for($x=$prev+1;$x<$n;$x++)$html.='<a class="btn ghost" href="'.$url($x).'">'.$x.'ページ</a>';
                 $html.='</div></details>';
             }
             $html.='<a class="btn '.($n===$page?'page-current':'ghost').'" href="'.$url($n).'"'.($n===$page?' aria-current="page"':'').'>'.$n.'</a>';
             $prev=$n;
         }
         if($page<$last)$html.='<a class="btn ghost" href="'.$url($page+1).'">次へ</a>';
         return $html.'</nav></div>';
     }
 }
