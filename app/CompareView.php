<?php
$base=$row['parent']??null;
$older=[];$seen=false;
foreach($cat['versions'] as $x){if($seen)$older[]=$x;if($x['tag']===$v)$seen=true;}
if($page==='compare'&&q('base')!=='')$base=q('base');
$valid=$base===null||in_array($base,array_column($older,'tag'),true);
$changes=$valid?$r->comparison($v,$base,$row['parent']??null):null;
$params=['v'=>$v];if($page==='compare'&&$base!==null)$params['base']=$base;
?>
<h2><?=$page==='compare'?'比較':'コミット'?> <code><?=h($row['upstream_commit']??$row['id'])?></code></h2>
<?php if($page==='compare'):?>
<form method="get" class="toolbar"><input type="hidden" name="page" value="compare"><label>比較元 <select name="base"><option value="">直前版</option><?php foreach($cat['versions'] as $x):?><option value="<?=h($x['tag'])?>" <?=$base===$x['tag']?'selected':''?>><?=h($x['tag'])?></option><?php endforeach?></select></label><label>比較先 <select name="v"><?php foreach($cat['versions'] as $x):?><option <?=$x['tag']===$v?'selected':''?>><?=h($x['tag'])?></option><?php endforeach?></select></label><button>比較</button></form>
<?php endif?>
<section class="panel commit-summary"><h3><?=h($row['message'])?></h3><p><?=h($cfg['display_name'].' (@'.$cfg['owner'].') ・ '.$row['date'])?></p><p><?=linkto($v,'code',['v'=>$v])?> ・ 比較元 <?=h($base??'未収録')?><?php if($page==='commit'&&$row['upstream_parent']):?> ・ GitHub報告の親 <?=h($row['upstream_parent'])?><?php endif?></p>
<?php if(!$valid):?><p>比較元には比較先より前に登録した版を選択してください。</p><?php elseif($base===null):?><p class="muted">初期収録版。親の公開ZIPが未収録のため行差分は未表示。</p><?php if($row['upstream_commit']==='693752f'):?><p>GitHub画面の報告値：58 件の変更ファイル <span class="add">+143,415</span> <span class="del">−75,917</span>（提供画像。独自再計算ではありません）</p><?php endif?>
<?php elseif($changes===null):?><p>比較データ未生成。取り込み時の比較対象・容量制限による場合があります。運用者による再計算後に閲覧できます。</p><?php else:$adds=array_sum(array_column($changes,'add'));$dels=array_sum(array_column($changes,'del'));?><p><?=count($changes)?> 件の変更ファイル <span class="add">+<?=$adds?></span> <span class="del">−<?=$dels?></span> <?php if(array_filter($changes,fn($c)=>$c['add']===null)):?>（処理制限・バイナリ差分の集計除外あり）<?php endif?></p><?php endif?></section>
<?php if($valid&&$base!==null&&$changes):
$search=substr(q('search'),0,160);$selected=q('path');$visible=[];$match=[];
foreach($changes as $c){if($search!==''){$d=$r->delta($v,$c['key'],$base,$row['parent']);$found=false;foreach($d['lines'] as $l)if(in_array($l[0],['a','d'],true)&&stripos($l[3],$search)!==false){$found=true;break;}if(!$found)continue;}$visible[]=$c;if($c['path']===$selected)$match=$c;}
if(!$match&&$visible)$match=$visible[0];
?>
<div class="section-grid diff-grid"><aside><input type="search" aria-label="変更ファイル検索" placeholder="変更ファイルを検索" data-filter="diff-list"><div id="diff-list"><?php foreach($visible as $c):?><a href="<?=h(url($page,$params+['path'=>$c['path'],'search'=>$search]))?>"><?=h($c['path'])?></a><?php endforeach?></div></aside><section>
<form method="get" class="toolbar"><input type="hidden" name="page" value="<?=h($page)?>"><?php foreach($params as $k=>$val):?><input type="hidden" name="<?=h($k)?>" value="<?=h($val)?>"><?php endforeach?><input type="search" aria-label="変更行検索" name="search" value="<?=h($search)?>" placeholder="変更行を検索"><button>検索</button></form><p class="muted"><?=count($visible)?> / <?=count($changes)?> ファイル（追加・削除行を検索。省略差分は対象外）</p>
<?php if($match):$d=$r->delta($v,$match['key'],$base,$row['parent']);$limit=500;$defaultOffset=0;if($search!=='')foreach($d['lines'] as $i=>$l)if(in_array($l[0],['a','d'],true)&&stripos($l[3],$search)!==false){$defaultOffset=intdiv($i,$limit)*$limit;break;}$offset=max(0,min((int)q('offset',(string)$defaultOffset),max(0,count($d['lines'])-1)));?><details class="panel diff" open><summary><?=h($match['path'])?> <span class="add">+<?=h($match['add']??'—')?></span> <span class="del">−<?=h($match['del']??'—')?></span></summary><?php if($d['binary']??false):?><p>バイナリファイル</p><?php elseif($d['limited']??false):?><p>容量・計算量制限のため行差分省略</p><?php else:?><div class="code-scroll" tabindex="0" role="region" aria-label="差分コード（横スクロール可能）"><table class="code-table" data-code-language="<?=h(codeLanguage($match['path']))?>" aria-label="変更前後の行番号と差分"><?php foreach(array_slice($d['lines'],$offset,$limit) as $l):?><tr class="diff-<?=h($l[0])?>"><td class="ln"><?=h($l[1])?></td><td class="ln"><?=h($l[2])?></td><td class="diff-code"><span aria-hidden="true"><?=h($l[0]==='a'?'+':($l[0]==='d'?'-':' '))?> </span><span class="diff-source"><?=h($l[3])?></span></td></tr><?php endforeach?></table></div><div class="pagination"><?php $p=$params+['path'=>$match['path'],'search'=>$search];if($offset>0)echo linkto('前の500行',$page,$p+['offset'=>max(0,$offset-$limit)]);if($offset+$limit<count($d['lines']))echo linkto('次の500行',$page,$p+['offset'=>$offset+$limit]);?></div><?php endif?></details><?php else:?><p>該当する変更行はありません。</p><?php endif?></section></div>
<?php endif?>
