<?php
declare(strict_types=1);
namespace KMM\Core;
/** The research team's d1.0.2 URL release is accepted as supplied, not re-researched. */
final class ResearchExternalLinks {
    public const VERSION='d1.0.2';
    public const MANIFEST_SHA256='d2cedd06f6e7228387eddcd6c51a09294db30f0f70c81219d39ae997ac49ba47';
    public const CATALOG_SHA256='e83f38320148dc283bce935d9321d3df72bcbea0b85f3f38b4cf2aed3f180cc1';
    public const TYPES=['official'=>'公式サイト','recruit'=>'採用案内','job'=>'求人・募集要項','entry'=>'応募ページ'];
    public static function safeUrl(mixed $url): bool {
        if(!is_string($url)||strlen($url)>2000||strlen($url)<12||preg_match('/[\x00-\x20\x7f<>\\\\]/',$url))return false;
        $p=parse_url($url);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass']))return false;
        $host=strtolower((string)$p['host']);
        if(!preg_match('/\A(?=.{4,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])\z/D',$host) || !str_contains($host,'.')
            ||str_contains($host,'..')||preg_match('/(?:\A|\.)(?:localhost|local|internal|test|invalid)\z/D',$host)
            ||filter_var($host,FILTER_VALIDATE_IP)!==false)return false;
        return !isset($p['port']) || ($p['port']>=1&&$p['port']<=65535);
    }
    /** A pinned manifest and bytewise catalog check are provenance checks, NOT live HTTP checks. */
    public static function parse(string $manifestBytes,string $catalogBytes):array {
        if(!hash_equals(self::MANIFEST_SHA256,hash('sha256',$manifestBytes)) || !hash_equals(self::CATALOG_SHA256,hash('sha256',$catalogBytes)))
            throw new \DomainException('Unsupported or modified research link release');
        $manifest=json_decode($manifestBytes,true,64,JSON_THROW_ON_ERROR);
        $catalog=json_decode($catalogBytes,true,128,JSON_THROW_ON_ERROR);
        if(!is_array($manifest)||($manifest['version']??null)!==self::VERSION||!is_array($catalog)
            ||($catalog['version']??null)!==self::VERSION||!is_array($catalog['companies']??null)
            ||count($catalog['companies'])!==59||($manifest['company_count']??null)!==59||($manifest['url_count']??null)!==141)
            throw new \DomainException('Research link release structure mismatch');
        $pinned=false;
        foreach(($manifest['files']??[]) as $file)if(($file['name']??null)==='JOB_LINK_CATALOG_d1.0.2.json'){
            $pinned=($file['bytes']??null)===strlen($catalogBytes) && hash_equals((string)($file['sha256']??''),hash('sha256',$catalogBytes));
        }
        if(!$pinned)throw new \DomainException('Manifest/catalog content mismatch');
        $items=[];$names=[];$categories=array_fill_keys(array_keys(self::TYPES),0);$dedupe=[];
        foreach($catalog['companies'] as $company){
            if(!is_array($company)||!is_string($company['company']??null)||!is_string($company['classification']??null)
                ||!is_string($company['notes']??null)||!is_string($company['checked_date']??null)
                ||!is_array($company['links']??null)||!is_array($company['source_status']??null))throw new \DomainException('Invalid company link entry');
            $companyName=$company['company'];
            if($companyName===''||strlen($companyName)>240||isset($names[$companyName]))throw new \DomainException('Duplicate or oversized company');
            $names[$companyName]=true;
            if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$company['checked_date']))throw new \DomainException('Invalid source date');
            foreach(self::TYPES as $kind=>$label){
                $urls=$company['links'][$kind]??null;$sourceStatus=$company['source_status'][$kind]??null;
                if(!is_array($urls)||!array_is_list($urls)||!is_array($sourceStatus)||!array_is_list($sourceStatus)||count($sourceStatus)!==count($urls))throw new \DomainException('Invalid link categories');
                foreach($urls as $i=>$url){
                    if(!self::safeUrl($url)||!is_string($sourceStatus[$i]))throw new \DomainException('Unsafe URL in source release');
                    $key=$companyName."\x00".$kind."\x00".$url;
                    if(isset($dedupe[$key]))throw new \DomainException('Duplicated company/category/URL');
                    $dedupe[$key]=true;$categories[$kind]++;
                    $id=substr(hash('sha256','kmm-research-link\x00'.$key),0,32);
                    $items[]=['id'=>$id,'source_company'=>$companyName,'classification'=>$company['classification'],
                        'kind'=>$kind,'url'=>$url,'source_status'=>$sourceStatus[$i],
                        'source_checked_on'=>$company['checked_date'],'source_notes'=>$company['notes']];
                }
            }
        }
        if(count($items)!==141 || $categories!==['official'=>26,'recruit'=>41,'job'=>66,'entry'=>8])throw new \DomainException('Research URL totals mismatch');
        return ['version'=>self::VERSION,'company_count'=>59,'url_count'=>141,'counts'=>$categories,'items'=>$items,
            'source_catalog_sha256'=>hash('sha256',$catalogBytes),'source_manifest_sha256'=>hash('sha256',$manifestBytes),
            'research_facts_accepted_as_supplied'=>true,'may_publish_without_mapping'=>false];
    }
    /** Pure, escaped component: only the repository may supply publicly mapped rows. */
    public static function buttons(array $rows,string $context='company'):string {
        $unique=[];
        foreach($rows as $row){
            $kind=$row['link_kind']??null;$url=$row['url']??null;
            if(!is_string($kind)||!isset(self::TYPES[$kind])||!self::safeUrl($url)||($row['visibility']??null)!=='published')continue;
            if(!isset($unique[$url]))$unique[$url]=[];
            if(!in_array($kind,$unique[$url],true))$unique[$url][]=$kind;
        }
        if($unique===[])return '';
        $html='';
        foreach($unique as $url=>$kinds){
            $label=in_array('entry',$kinds,true)&&count($kinds)>1?'求人・応募ページ':self::TYPES[$kinds[0]];
            $html.='<a class="btn ghost external-link" href="'.Security::h($url).'" target="_blank" rel="noopener noreferrer nofollow external"'
                .' aria-label="'.Security::h($label.'（外部サイト、新しいタブ）').'">'.Security::h($label).' ↗</a>';
        }
        return '<div class="external-links" aria-label="外部の公式・採用・求人リンク"><div class="external-link-actions">'.$html.'</div>'
            .'<p class="note">外部サイトを新しいタブで開きます。募集ページの掲載は受付継続・採用枠・応募資格を保証しません。応募操作はサイト外で行います。</p></div>';
    }

}
