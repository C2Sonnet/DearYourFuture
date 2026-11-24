<?php
declare(strict_types=1);
namespace KMM\Core;

/** R06 source-ordered selection flow. Missing steps must never become invented rounds. */
final class PrivateR06SelectionFlow {
    public static function html(array $record):string {
        if(Identity::current()===null)return '';
        $raw=$record['selection_steps']??null;
        $steps=[];
        if(is_array($raw)){
            foreach(array_slice($raw,0,24) as $s){
                // Only explicitly recorded single step labels are accepted.
                if(!is_string($s)||trim($s)==='')continue;
                $label=trim(preg_replace('/[\r\n\t]+/u',' ', $s)??'');
                if($label!=='' && strlen($label)<=480)$steps[]=$label;
            }
        }
        $html='<section class="section r06-selection"><h2>選考フロー</h2>';
        if($steps===[]){
            $src=$record['source_record']??[];
            $url=is_array($src)?($src['source_url']??null):null;
            if(!ResearchExternalLinks::safeUrl($url)){
                $latest=ResearchR07::job((string)($record['source_job_id']??''));
                $url=$latest['source_url']??null;
            }
            return $html.'<p>選考工程は未収録です。求人原典でご確認ください。</p>'
                .(ResearchExternalLinks::safeUrl($url)?ResearchR07::linkButtons([$url=>'source_url']):'').'</section>';
        }
        // Generate a valid, conservative Mermaid flowchart. Keep untrusted labels in quotes.
        $source="flowchart TD\n";
        $text='<ol class="r06-selection-text">';
        $diagram='<div class="r06-selection-visual" aria-hidden="true">';
        foreach($steps as $index=>$label){
            $id='s'.($index+1);
            $safeLabel=str_replace(['\\','"','[',']','<','>','`'],[' ', '＂','（','）','＜','＞',''], $label);
            $source.='    '.$id.'["'.$safeLabel.'"]' . "\n";
            if($index>0)$source.='    s'.$index.' --> '.$id."\n";
            $text.='<li>'.Security::h($label).'</li>';
            if($index>0)$diagram.='<span class="r06-selection-arrow" aria-hidden="true">↓</span>';
            $diagram.='<div class="r06-selection-node">'.Security::h($label).'</div>';
        }
        $diagram.='</div>';$text.='</ol>';

        // Mermaid source stays plain escaped text; scripts are same-origin only under CSP.
        // The always-present fallback diagram and ordered text cover JS-disabled/error cases.
        $html.='<div data-r06-mermaid><div class="r06-mermaid-render" data-r06-mermaid-output hidden></div>';
        $html.='<div data-r06-mermaid-fallback>'.$diagram.'</div>';
        $html.='<h3>選考工程（テキスト）</h3>'.$text;
        $html.='<details class="r06-mermaid-source"><summary>Mermaid flowchart TD ソースを表示</summary>'
            .'<pre><code class="language-mermaid" data-r06-mermaid-source>'.Security::h($source).'</code></pre></details>';
        $html.='</div>';
        return $html.'</section>';
    }
}
