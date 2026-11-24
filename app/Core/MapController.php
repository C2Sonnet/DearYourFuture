<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Server rendered geographic coordinate plot with accessible list and opt-in OSM links. */
final class MapController {
    public static function isRoute(string $path):bool {
        return $path==='/map'||preg_match('~\A/companies/[a-f0-9]{32}/map\z~D',$path)===1;
    }
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){header('Allow: GET');http_response_code(405);return;}
        $db=Database::connected();
        $companyId=null;$title='全企業の拠点マップ';
        if($path!=='/map'){
            preg_match('~\A/companies/([a-f0-9]{32})/map\z~D',$path,$m);$companyId=$m[1];
            $company=BusinessRepository::read($db,'companies',$companyId,true);
            if($company===null){http_response_code(404);Kernel::layout('企業が見つかりません','<h1>企業が見つかりません</h1>');return;}
            $title=(string)$company['display_name'].' の拠点マップ';
        }
        $kind=(string)($_GET['kind']??'all');
        if($kind!=='all'&&!in_array($kind,BusinessData::LOCATION_KINDS,true)){$kind='all';}
        $workOnly=($_GET['worksite']??'')==='confirmed';
        $region=trim(is_string($_GET['region']??null)?$_GET['region']:'');
        $category=is_string($_GET['category']??null)?$_GET['category']:'';
        $evidence=is_string($_GET['evidence']??null)?$_GET['evidence']:'';
        if(strlen($region)>80||preg_match('/[\x00-\x1f\x7f]/',$region)){$region='';}
        if($category!==''&&!in_array($category,['pastry','bread','research','product_development','quality_control','other'],true))$category='';
        if($evidence!==''&&!in_array($evidence,MapRepository::EVIDENCE_TYPES,true))$evidence='';
        $pins=MapRepository::pins($db,$companyId,$kind==='all'?null:$kind,$workOnly,$region,$category,$evidence);
        $unplaced=$companyId===null?MapRepository::unpublishedPinCount($db):($pins===[]?1:0);
        $base=$companyId===null?'/map':'/companies/'.$companyId.'/map';
        $html='<section class="hero compact"><span class="eyebrow">VERIFIED LOCATIONS · v0.1.4</span><h1>'.Security::h($title).'</h1><p>確かな出典で確認した座標だけをピン表示。本社と求人の実勤務先は異なる場合があります。</p></section>';
        $html.='<form method="get" action="'.Security::h($base).'" class="map-filter"><label>拠点種類 <select name="kind">';
        $labels=['all'=>'すべて','headquarters'=>'本社','registered_office'=>'登記住所','office'=>'オフィス','factory'=>'工場','development'=>'開発センター','shop'=>'店舗','other'=>'その他'];
        foreach($labels as $k=>$name)$html.='<option value="'.Security::h($k).'"'.($kind===$k?' selected':'').'>'.Security::h($name).'</option>';
        $html.='</select></label><label>住所に含む地域<input type="text" name="region" maxlength="80" value="'.Security::h($region).'"></label><label>職種<select name="category"><option value="">指定しない</option>';
        foreach(['pastry'=>'製菓','bread'=>'製パン','research'=>'研究','product_development'=>'商品開発','quality_control'=>'品質管理','other'=>'その他'] as $key=>$label)$html.='<option value="'.$key.'"'.($category===$key?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>根拠の種類<select name="evidence"><option value="">すべての確かな根拠</option>';
        foreach(MapRepository::EVIDENCE_TYPES as $e)$html.='<option value="'.Security::h($e).'"'.($evidence===$e?' selected':'').'>'.Security::h(BusinessData::sourceTypeLabel($e)).'</option>';
        $html.='</select></label><label><input type="checkbox" name="worksite" value="confirmed"'.($workOnly?' checked':'').'> 勤務地として確認済みのみ</label><button class="btn primary" type="submit">絞り込む</button></form>';
        $html.='<p class="note">表示ピン '.count($pins).' 件。';
        if($companyId===null)$html.=' 地図に掲載できない公開企業 '.(int)$unplaced.' 社（出典付き座標未登録）。';
        $html.='座標に基づく概略図です。地形・道路・所要時間は表示していません。</p>';
        $html.=self::svgMap($pins);
        $html.='<section class="section"><h2>拠点一覧・地図を開く</h2>';
        if(!$pins)$html.='<p>出典と座標を確認できた拠点がまだありません。未確認の会社に推測ピンは置きません。</p>';
        foreach($pins as $p){
            $type=($p['kind']==='headquarters'?'本社':(($p['is_confirmed_workplace']??0)?'求人の確認済み勤務地':'事業拠点'));
            $url=self::osmLink((float)$p['latitude'],(float)$p['longitude']);
            $html.='<article class="panel map-row" id="pin-'.Security::h($p['id']).'"><h3>'.Security::h($p['company_name']).' — '.Security::h($p['name']).'</h3><p>'.Security::h($type).' / '.Security::h($p['address']??'所在地詳細未掲載').' / 確認日：'.Security::h($p['verified_on']).'</p><p>根拠：'.Security::h($p['source_title']).'</p><a href="/companies/'.Security::h($p['company_id']).'" class="text-link">企業詳細</a>'.(!empty($p['confirmed_job_id'])?' · <a href="/jobs/'.Security::h($p['confirmed_job_id']).'" class="text-link">この拠点を勤務地とする求人</a>':'').' · <a href="'.Security::h($url).'" target="_blank" rel="noopener noreferrer" class="text-link">OpenStreetMapで位置を確認</a></article>';
        }
        $html.='</section>';
        Kernel::layout($title,$html);
    }
    public static function osmLink(float $lat,float $lon):string {
        return 'https://www.openstreetmap.org/?mlat='.rawurlencode((string)$lat).'&mlon='.rawurlencode((string)$lon).'#map=15/'.$lat.'/'.$lon;
    }
    /** Spatial clustering of verified pins for legibility. Full verified locations remain in the accessible list. */
    public static function groups(array $points):array {
        if(!$points)return [];
        $xs=array_map(static fn($p)=>(float)$p['longitude'],$points);$ys=array_map(static fn($p)=>(float)$p['latitude'],$points);
        $west=min($xs);$east=max($xs);$south=min($ys);$north=max($ys);
        $dx=max(.04,$east-$west)*1.24;$dy=max(.04,$north-$south)*1.24;
        $midX=($west+$east)/2;$midY=($south+$north)/2;$bins=[];
        foreach($points as $p){
            $x=36+728*((float)$p['longitude']-($midX-$dx/2))/$dx;
            $y=425-390*((float)$p['latitude']-($midY-$dy/2))/$dy;
            $key=(int)floor($x/34).':'.(int)floor($y/34);
            if(!isset($bins[$key]))$bins[$key]=['x'=>0.0,'y'=>0.0,'pins'=>[]];
            $bins[$key]['x']+=$x;$bins[$key]['y']+=$y;$bins[$key]['pins'][]=$p;
        }
        $result=[];foreach($bins as $group){$n=count($group['pins']);$group['x']=round($group['x']/$n,2);$group['y']=round($group['y']/$n,2);$group['count']=$n;$result[]=$group;}
        return $result;
    }
    /** Coordinates plotted using a normalized lon/lat grid; no fabricated geography. */
    public static function svgMap(array $pins):string {
        if($pins===[])return '<div class="map-skeleton" role="status">📍 現在、公開可能な確認済みピンはありません。</div>';
        $lon=array_map(static fn($p)=>(float)$p['longitude'],$pins);
        $lat=array_map(static fn($p)=>(float)$p['latitude'],$pins);
        $west=min($lon);$east=max($lon);$south=min($lat);$north=max($lat);
        $lonSpan=max(.04,$east-$west);$latSpan=max(.04,$north-$south);
        $minX=($west+$east)/2-$lonSpan*.62;$maxX=($west+$east)/2+$lonSpan*.62;
        $minY=($south+$north)/2-$latSpan*.62;$maxY=($south+$north)/2+$latSpan*.62;
        $html='<div class="coordinate-map"><svg viewBox="0 0 800 460" role="img" aria-label="確認済み事業拠点の緯度経度概略図" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="796" height="456" rx="25" fill="none" stroke="currentColor" opacity=".18"/>';
        for($i=1;$i<=3;$i++){
            $x=($i*200);$y=($i*115);$html.='<line x1="'.$x.'" y1="15" x2="'.$x.'" y2="445" stroke="currentColor" opacity=".12"/><line x1="15" y1="'.$y.'" x2="785" y2="'.$y.'" stroke="currentColor" opacity=".12"/>';
        }
        foreach(self::groups($pins) as $group){
            $p=$group['pins'][0];$x=$group['x'];$y=$group['y'];$count=$group['count'];
            $kind=$count>1?'cluster':(($p['is_confirmed_workplace']??0)?'workplace':($p['kind']==='headquarters'?'headquarters':'other'));
            $id=Security::h((string)$p['id']);
            $label=$count>1?($count.'件の確認済み拠点（下の一覧に全件表示）'):($p['company_name'].'：'.$p['name']);
            $html.='<a href="#pin-'.$id.'" aria-label="'.Security::h($label).'"><circle class="map-point '.Security::h($kind).'" cx="'.$x.'" cy="'.$y.'" r="'.($count>1?15:10).'"><title>'.Security::h($label).'</title></circle>';
            if($count>1)$html.='<text x="'.$x.'" y="'.($y+4).'" text-anchor="middle" class="map-point-count">'.$count.'</text>';
            else $html.='<text x="'.($x+13).'" y="'.($y-11).'" class="map-point-label">'.Security::h((string)$p['name']).'</text>';
            $html.='</a>';
        }
        $html.='<text x="20" y="442" class="map-grid-label">東西：経度 ／ 南北：緯度（正距円筒図法の簡易表示）</text></svg></div><p class="map-legend">● 本社　● 求人の確認済み勤務地　● その他の確認済み拠点 ／ 近接点は件数表示にまとめ、下の全件一覧へ移動できます。</p>';
        return $html;
    }
}
