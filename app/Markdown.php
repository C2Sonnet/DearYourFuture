<?php
declare(strict_types=1);
// Deliberately restricted Markdown subset. Raw HTML, remote images and active content never execute.
function mdSlug(string $s):string{
 $s=strtolower(trim(str_replace(['`','*','~'], '',$s)));
 $s=preg_replace('/[^\p{L}\p{N}_\-\s]/u','',$s)??'';
 return preg_replace('/\s+/u','-',$s)?:'section';
}
function mdPrefix(string $v,string $file):string{return 'md-'.substr(hash('sha256',$v."\0".$file),0,12).'-';}
function mdInline(string $s,string $v,string $file,array $files):string{
 // Use a delimiter that is not part of Markdown strikethrough.
 $pattern='@(`+)([^`\n]+)\1|(!?)\[([^\]\n]*)\]\(([^)\s]+)\)|\*\*([^*\n]+)\*\*|(?<!\*)\*([^*\n]+)\*(?!\*)|~~([^~\n]+)~~@u';
 $ok=preg_match_all($pattern,$s,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
 if(!$ok)return h($s);
 $out='';$end=0;
 foreach($matches as $m){[$full,$pos]=$m[0];$out.=h(substr($s,$end,$pos-$end));$end=$pos+strlen($full);
  if($m[1][0]!==null){$out.='<code>'.h($m[2][0]).'</code>';continue;}
  if($m[3][0]!==null){$image=$m[3][0]==='!';$label=h($m[4][0]);$ref=$m[5][0];$outPart=$label;
   if(preg_match('~^https?://~i',$ref)&&filter_var($ref,FILTER_VALIDATE_URL)){if(!$image)$outPart='<a rel="noreferrer noopener" href="'.h($ref).'">'.$label.'</a>';}
   elseif(!preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i',$ref)){
    [$relative,$fragment]=array_pad(explode('#',$ref,2),2,'');$path=$relative===''?$file:resolvePath(dirname($file),$relative);
    if($path!==null&&isset($files[$path])){
     if($image){if(preg_match('/\.(png|jpe?g|webp|gif)$/i',$path))$outPart='<img loading="lazy" src="'.h(url('image',['v'=>$v,'path'=>$path])).'" alt="'.$label.'">';}
     else{$params=['v'=>$v,'path'=>$path];$anchor='';if($fragment!==''&&($path===$file||preg_match('/\.md$/i',$path))){$anchor='#'.mdPrefix($v,$path).'h-'.mdSlug(rawurldecode($fragment));$params['view']='rendered';}elseif(preg_match('/^L[1-9]\d*$/D',$fragment)){$params['line']=max(1,(int)substr($fragment,1));$anchor='#'.$fragment;}
      $href=$path===$file&&$fragment!==''&&$anchor!==''?$anchor:url('file',$params).$anchor;$outPart='<a href="'.h($href).'">'.$label.'</a>';}
    }
   }$out.=$outPart;continue;
  }
  foreach([6=>'strong',7=>'em',8=>'del'] as $i=>$tag)if($m[$i][0]!==null){$out.='<'.$tag.'>'.h($m[$i][0]).'</'.$tag.'>';break;}
 }return $out.h(substr($s,$end));
}
function mdCells(string $s):array{return array_map('trim',preg_split('/(?<!\\\\)\|/',trim(trim($s),'|'))?:[]);}
function markdown(string $md,string $v,string $file,array $files):string{
 if(strlen($md)>1000000)return '<p class="empty">大容量のためMarkdown表示を省略します。ソースファイルから確認できます。</p>';
 $prefix=mdPrefix($v,$file);$heads=[];$out=[];$seen=[];$paragraph=[];$list=null;$fence=null;$fenceLang='';$code=[];$lines=preg_split('/\r\n|\n|\r/',$md)?:[];
 $inline=fn($s)=>mdInline($s,$v,$file,$files);
 $flush=function()use(&$paragraph,&$out,$inline){if($paragraph){$out[]='<p>'.$inline(implode(' ',$paragraph)).'</p>';$paragraph=[];}};
 $closeList=function()use(&$list,&$out){if($list!==null){$out[]='</'.$list.'>';$list=null;}};
 for($i=0;$i<count($lines);$i++){$line=$lines[$i];
  if($fence!==null){if(preg_match('/^\s*'.preg_quote($fence,'/').'\s*$/D',$line)){$out[]='<pre><code'.($fenceLang!==''?' data-code-language="'.h($fenceLang).'"':'').'>'.h(implode("\n",$code)).'</code></pre>';$fence=null;$code=[];}else $code[]=$line;continue;}
  if(preg_match('/^\s*(`{3,}|~{3,})(.*)$/D',$line,$m)){$flush();$closeList();$fence=$m[1];$fenceLang=preg_match('/^\s*([a-zA-Z0-9_-]{1,30})(?:\s|$)/D',$m[2],$lang)?strtolower($lang[1]):'';continue;}
  $level=0;$label='';if(preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u',$line,$m)){$level=strlen($m[1]);$label=$m[2];}elseif(trim($line)!==''&&isset($lines[$i+1])&&preg_match('/^\s*(=+|-+)\s*$/D',$lines[$i+1],$m)&&!preg_match('/^\s*[-*+]\s/',$line)){$level=$m[1][0]==='='?1:2;$label=trim($line);$i++;}
  if($level){$flush();$closeList();$slug=mdSlug($label);$n=$seen[$slug]??0;$id=$prefix.'h-'.$slug.($n?'-'.$n:'');$seen[$slug]=$n+1;while(in_array($id,array_column($heads,0),true))$id.='-1';$heads[]=[$id,$label,$level];$out[]="<h$level id=\"".h($id)."\">".$inline($label)."</h$level>";continue;}
  if(str_contains($line,'|')&&isset($lines[$i+1])&&str_contains($lines[$i+1],'|')){$cells=mdCells($line);$sep=mdCells($lines[$i+1]);if(count($cells)===count($sep)&&count($cells)>1&&!array_filter($sep,fn($x)=>!preg_match('/^:?-{3,}:?$/D',$x))){$flush();$closeList();$out[]='<div class="md-table-scroll"><table><thead><tr>'.implode('',array_map(fn($x)=>'<th scope="col">'.$inline($x).'</th>',$cells)).'</tr></thead><tbody>';$i++;while(isset($lines[$i+1])&&str_contains($lines[$i+1],'|')&&count($row=mdCells($lines[$i+1]))===count($cells)){$i++;$out[]='<tr>'.implode('',array_map(fn($x)=>'<td>'.$inline($x).'</td>',$row)).'</tr>';}$out[]='</tbody></table></div>';continue;}}
  if(preg_match('/^\s*(?:([-*+])|([1-9]\d*)[.)])\s+(.+)$/u',$line,$m)){$flush();$kind=$m[1]!==''?'ul':'ol';if($list!==$kind){$closeList();$list=$kind;$out[]='<'.$kind.($kind==='ol'?' start="'.(int)$m[2].'"':'').'>';}$out[]='<li>'.$inline($m[3]).'</li>';continue;}
  $closeList();if(trim($line)===''){$flush();continue;}
  if(preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/D',$line)){$flush();$out[]='<hr>';continue;}
  if(preg_match('/^>\s?(.*)$/u',$line,$m)){$flush();$out[]='<blockquote>'.$inline($m[1]).'</blockquote>';continue;}
  $paragraph[]=$line;
 }
 $flush();$closeList();if($fence!==null)$out[]='<pre><code'.($fenceLang!==''?' data-code-language="'.h($fenceLang).'"':'').'>'.h(implode("\n",$code)).'</code></pre>';
 $toc='';if($heads){$listId=$prefix.'toc';$toc='<details class="toc"><summary aria-label="見出し目次">☷</summary><div class="popover"><label class="sr-only" for="'.h($listId.'-search').'">見出し検索</label><input id="'.h($listId.'-search').'" type="search" aria-label="見出し検索" placeholder="見出しを検索" data-filter="'.h($listId).'"><div class="toc-list" id="'.h($listId).'">';foreach($heads as [$id,$label,$level])$toc.='<a class="toc-item toc-depth-'.$level.'" href="#'.h($id).'">'.h($label).'</a>';$toc.='</div></div></details>';}
 return $toc.'<div class="markdown">'.implode("\n",$out).'</div>';
}
