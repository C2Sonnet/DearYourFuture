<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Company location browsing: area/query filter and 20-row pagination, source data immutable. */
final class ResearchMapController {
    public static function areaMatches(?string $address,string $area):bool {
        if($area==='all')return true;
        if($area==='unknown')return $address===null;
        if($address===null)return false;
        $a=trim(preg_replace('/\A東京[・･]/u','東京都',$address)??$address);
        if($area==='tokyo_all')return str_starts_with($a,'東京都');
        if($area==='tokyo_wards')return preg_match('/\A東京都(?:千代田|中央|港|新宿|文京|台東|墨田|江東|品川|目黒|大田|世田谷|渋谷|中野|杉並|豊島|北|荒川|板橋|練馬|足立|葛飾|江戸川)区/u',$a)===1;
        if($area==='tokyo_outside')return str_starts_with($a,'東京都')&&!self::areaMatches($a,'tokyo_wards');
        return str_starts_with($a,$area);
    }
    public static function selectRows(array $companies,string $q,string $area):array {
        $rows=[];
        foreach($companies as $id=>$c){
            $name=ResearchR07::displayName($c);$address=ResearchR07::address($c);
            if(!self::areaMatches($address,$area))continue;
            if($q!==''&&mb_stripos($name.' '.($address??''),$q)===false)continue;
            $rows[]=['id'=>$id,'name'=>$name,'address'=>$address];
        }
        usort($rows,static fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));return $rows;
    }
    public static function handle(string $method):void {
        if($method!=='GET'){http_response_code(405);header('Allow: GET');return;}
        if(Identity::current()===null){header('Location: /account/login?next=%2Fmap',true,302);return;}
        $db=Database::connected();
        $q=trim(is_string($_GET['q']??null)?$_GET['q']:'');if(mb_strlen($q)>80)$q=mb_substr($q,0,80);
        $area=is_string($_GET['area']??null)?$_GET['area']:'tokyo_all';
        $prefectures=[];foreach(ResearchR07::companies() as $co){$pref=ResearchR07::prefecture($co);if($pref!==null)$prefectures[$pref]=true;}
        ksort($prefectures);
        $areas=['tokyo_all'=>'東京都全域','tokyo_wards'=>'東京都内（23区）','tokyo_outside'=>'東京都下（23区以外）'];
        foreach(array_keys($prefectures) as $pref)if($pref!=='東京都')$areas[$pref]=$pref;
        $areas=['all'=>'全国・全所在地']+$areas+['unknown'=>'住所未収録'];
        if(!isset($areas[$area]))$area='tokyo_all';
        $filtered=self::selectRows(ResearchR07::companies(),$q,$area);
        $total=count($filtered);$last=max(1,(int)ceil($total/20));
        $page=is_string($_GET['page']??null)&&preg_match('/\A[1-9][0-9]{0,3}\z/D',$_GET['page'])?min($last,(int)$_GET['page']):1;
        $slice=array_slice($filtered,($page-1)*20,20);
        $markers=[];foreach($slice as $r)if($r['address']!==null)$markers[]=['name'=>$r['name'],'address'=>$r['address'],'url'=>ResearchR07::companyUrl($db,$r['id'])];
        $html='<section class="hero compact"><span class="eyebrow">COMPANY LOCATION MAP</span><h1>全企業の拠点マップ</h1><p>掲載所在地をGoogleマップで確認できます。企業所在地と実際の勤務場所は必ずしも同じではありません。</p></section>';
        $html.='<section class="section"><h2>所在地で絞り込む</h2><form class="map-filter" method="get" action="/map"><label>企業名・住所<input type="search" name="q" maxlength="80" value="'.Security::h($q).'" placeholder="企業名・市区町村など"></label><label>都道府県・地域<select name="area">';
        foreach($areas as $key=>$name)$html.='<option value="'.Security::h($key).'"'.($key===$area?' selected':'').'>'.Security::h($name).'</option>';
        $html.='</select></label><button class="btn primary">検索</button></form></section>';
        $start=$total===0?0:($page-1)*20+1;$end=min($page*20,$total);
        $info='結果数：'.$total.' 件｜今は第 '.$start.' 件 ～ 第 '.$end.' 件';
        $html.='<section class="section"><h2>Google マップ</h2><p class="note">選択中のページにある住所収録企業を表示します。住所は原典記録で、ピンは勤務地の確定表示ではありません。</p>';
        $key=self::key($db);$mapId=self::mapId($db);
        if($key!==''&&$markers!==[]){
            $json=json_encode($markers,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
            $html.='<div id="r07-google-map" data-api-key="'.Security::h($key).'" data-map-id="'.Security::h($mapId).'" role="region" aria-label="企業拠点地図"></div><script type="application/json" id="r07-google-locations">'.$json.'</script><script src="/assets/r07-google-map.js" defer></script><p id="r07-map-status" role="status">地図を読み込みます。</p>';
        }else $html.='<p class="note">'.($key===''?'Google Maps APIキーが未登録です。管理者は地図設定を確認してください。':'このページには住所が収録された企業がありません。').'</p>';
        $html.='</section><section class="section"><h2>企業所在地一覧</h2><div class="directory-page-info">'.Security::h($info).'</div><div class="r07-map-list compact-map-grid">';
        foreach($slice as $row){$html.='<article class="panel"><strong>'.Security::h($row['name']).'</strong><p>'.Security::h($row['address']??'住所未収録').'</p>'.GoogleMapsLink::button($row['address']).'<a class="btn ghost" href="'.Security::h(ResearchR07::companyUrl($db,$row['id'])).'">企業詳細</a></article>';}
        if($slice===[])$html.='<p class="note">条件に合う企業はありません。</p>';
        $html.='</div>'.self::pagination($q,$area,$page,$last,$total,$info).'</section>';
        Kernel::layout('全企業の拠点マップ',$html);
    }
    private static function pagination(string $q,string $area,int $page,int $last,int $total,string $info):string {
        $url=static fn(int $n):string=>Security::h('/map?'.http_build_query(['q'=>$q,'area'=>$area,'page'=>$n]));
        $html='<div class="directory-pager"><span class="directory-page-info">'.Security::h($info).'</span><nav class="directory-pages" aria-label="所在地一覧のページ切替">';
        if($page>1)$html.='<a class="btn ghost" href="'.$url($page-1).'">前へ</a>';
        $visible=[];foreach([1,2,$last-1,$last,$page-1,$page,$page+1] as $i)if($i>0&&$i<=$last)$visible[$i]=true;
        ksort($visible);$prev=0;
        foreach(array_keys($visible) as $i){
            if($prev&&$i>$prev+1){
                $html.='<details class="directory-ellipsis"><summary class="btn ghost" aria-label="省略したページから選択">...</summary><div class="directory-ellipsis-menu">';
                for($n=$prev+1;$n<$i;$n++)$html.='<a class="btn ghost" href="'.$url($n).'">'.$n.'ページ</a>';
                $html.='</div></details>';
            }
            $html.='<a class="btn '.($i===$page?'page-current':'ghost').'" href="'.$url($i).'"'.($i===$page?' aria-current="page"':'').'>'.$i.'</a>';
            $prev=$i;
        }
        if($page<$last)$html.='<a class="btn ghost" href="'.$url($page+1).'">次へ</a>';
        return $html.'</nav></div>';
    }
    public static function mapId(PDO $db):string {
        $stmt=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? LIMIT 1');$stmt->execute(['maps.google.map_id']);$v=json_decode((string)$stmt->fetchColumn(),true);return is_string($v)&&preg_match('/\A[a-fA-F0-9]{16}\z/D',$v)?$v:'';
    }
    public static function key(PDO $db):string {
        $stmt=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');$stmt->execute(['maps.google.browser_key']);$v=json_decode((string)$stmt->fetchColumn(),true);return is_string($v)&&preg_match('/\AAIza[0-9A-Za-z_-]{25,90}\z/D',$v)?$v:'';
    }
}
