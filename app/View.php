<?php
declare(strict_types=1);
function h(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $page='code',array $p=[]):string{return '/?'.http_build_query(['page'=>$page]+$p,'','&',PHP_QUERY_RFC3986);}
function linkto(string $name,string $page,array $p=[],string $fragment=''):string{return '<a href="'.h(url($page,$p).$fragment).'">'.h($name).'</a>';}
function asset(string $name):string{static $cache=[];return $cache[$name]??=('/assets/'.$name.'?rev='.substr(hash_file('sha256',__DIR__.'/../public/assets/'.$name),0,16));}
function codeLanguage(string $path):string{return ['php'=>'php','js'=>'javascript','mjs'=>'javascript','ts'=>'typescript','css'=>'css','html'=>'xml','htm'=>'xml','xml'=>'xml','py'=>'python','sql'=>'sql','sh'=>'bash','bash'=>'bash','json'=>'json','yaml'=>'yaml','yml'=>'yaml','md'=>'markdown','ini'=>'ini','conf'=>'nginx','nginx'=>'nginx','diff'=>'diff','c'=>'c','cpp'=>'cpp','java'=>'java','rs'=>'rust','rb'=>'ruby','go'=>'go','ps1'=>'powershell','bat'=>'dos'][strtolower(pathinfo($path,PATHINFO_EXTENSION))]??'';}
function q(string $k,string $default=''):string{return is_string($_GET[$k]??null)?$_GET[$k]:$default;}
function resolvePath(string $base,string $ref):?string{$parts=explode('/',($base==='.'?'':$base.'/').rawurldecode($ref));$out=[];foreach($parts as $x){if($x===''||$x==='.')continue;if($x==='..'){if(!$out)return null;array_pop($out);}else $out[]=$x;}return implode('/',$out);}
require_once __DIR__.'/Markdown.php';

function breadcrumbs(string $repo,string $v,string $path,bool $file=false):string{
 $o=linkto($repo,'code',['v'=>$v]);$parts=explode('/',$path);$prefix='';foreach($parts as $i=>$part){$prefix.=($prefix===''?'':'/').$part;$o.=' / '.(($file&&$i===count($parts)-1)?h($part):linkto($part,'code',['v'=>$v,'dir'=>$prefix]));}return $o;
}
function versionRow(array $cat,string $tag):?array{foreach($cat['versions'] as $x)if($x['tag']===$tag)return $x;return null;}
function latestFileRow(array $cat,array $files,string $prefix,bool $dir,string $v):array{
 $tags=[];foreach($files as $n=>$f)if($dir?str_starts_with($n,$prefix.'/'):$n===$prefix)$tags[$f['last_changed']??$v]=true;
 foreach($cat['versions'] as $x)if(isset($tags[$x['tag']]))return $x;return versionRow($cat,$v)??[];
}

function commitPopover(array $row,array $cfg):string{
 $id=$row['upstream_commit']??$row['id'];$reported=($row['parent']??null)===null&&($row['upstream_commit']??'')==='693752f';
 $stats=$reported?[58,143415,75917]:((isset($row['parent']))?[(int)$row['changes'],(int)$row['add'],(int)$row['del']]:null);
 $o='<details class="commit-pop"><summary title="コミット概要" aria-label="コミット '.h($id).' の概要">'.h($id).'</summary><div class="popover"><strong>'.h($row['message']).'</strong>';
 if($stats){[$count,$a,$d]=$stats;$o.='<p>'.number_format($count).' 件の変更ファイル <span class="add">+'.number_format($a).'</span> <span class="del">−'.number_format($d).'</span></p>';
 if($a+$d>0){$w=100*$a/($a+$d);$o.='<svg class="change-bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect class="change-add" x="0" y="0" width="'.number_format($w,5,'.','').'" height="8"/><rect class="change-del" x="'.number_format($w,5,'.','').'" y="0" width="'.number_format(100-$w,5,'.','').'" height="8"/></svg>';}
 $o.='<small class="muted">'.($reported?'提供画像の報告値・未再計算':(!empty($row['limited'])?'大容量・バイナリ差分は行数集計外':'収録版から計算')).'</small>';}
 else $o.='<p class="muted">比較元の収録なし（差分未計算）</p>';
 return $o.'<hr><div class="commit-author"><img class="avatar small" src="'.h(asset('author-avatar.png')).'" alt="">'.h($cfg['display_name']??$cfg['owner']).' (@'.h($cfg['owner']).') ・ '.h($row['date']?:'日付未確認').'</div>'.linkto('コミット詳細','commit',['v'=>$row['tag']]).'</div></details>';
}
function languageView(array $languages):string{
 $languages=array_filter($languages,fn($b)=>is_numeric($b)&&$b>0);arsort($languages);$sum=array_sum($languages);
 if(!$sum)return '<p class="muted">対象言語なし</p>';
 $colors=['PHP'=>'php','CSS'=>'css','JavaScript'=>'js','Python'=>'py','HTML'=>'html','SQL'=>'sql','Shell'=>'shell'];
 $bar='<svg class="langbar-svg" viewBox="0 0 100000 10" preserveAspectRatio="none" role="img" aria-label="言語別バイト比">';$list='<div class="langlist">';$pos=0;
 foreach($languages as $n=>$b){$end=$pos+$b;$x=100000*$pos/$sum;$width=100000*$end/$sum-$x;$class='lang-'.($colors[$n]??'other');$pct=100*$b/$sum;$label=$pct<0.1?'0.1%未満':number_format($pct,1).'%';
 $bar.='<rect class="'.h($class).'" x="'.number_format($x,6,'.','').'" y="0" width="'.number_format($width,6,'.','').'" height="10"><title>'.h($n.' '.$label.' / '.number_format($b).' バイト').'</title></rect>';
 $list.='<span title="'.number_format($b).' バイト"><i class="dot '.h($class).'"></i> <b>'.h($n).'</b> <span class="muted">'.h($label).'</span></span>';$pos=$end;}
 return $bar.'</svg>'.$list.'</div><small class="muted">対象拡張子のバイト比（public/assets/vendor 除外）。SQLを含む独自集計で、GitHubのLinguistとは異なります。</small>';
}
