/* Highlight.js operates only on text already escaped by PHP. No CDN or auto-detection. */
(()=>{
  'use strict';
  const highlighter=globalThis.hljs;
  if(!highlighter)return;
  const aliases={js:'javascript',ts:'typescript',html:'xml',sh:'bash',yml:'yaml',py:'python',text:'plaintext'};
  function language(name){name=aliases[name]||name;return name&&highlighter.getLanguage(name)?name:null;}
  // Retain line numbers, whitespace, clipboard source and multi-line grammar context.
  // Interpret only generated span tokens; never insert source text as markup.
  function highlightCells(cells,lang){
    const original=cells.map(c=>c.textContent),source=original.join('\n');
    if(!lang||!source||source.length>100000||original.some(x=>x.length>8192))return;
    try{
      const output=highlighter.highlight(source,{language:lang,ignoreIllegals:true}).value;
      const tpl=document.createElement('template');tpl.innerHTML=output;
      if(tpl.content.textContent!==source)return;
      const fragments=original.map(()=>document.createDocumentFragment());let line=0;
      function append(text,classes){
        const chunks=text.split('\n');
        for(let i=0;i<chunks.length;i++){
          if(i)line++;
          if(!fragments[line])throw Error('line mismatch');
          if(!chunks[i])continue;
          let parent=fragments[line];
          for(const cls of classes){const span=document.createElement('span');span.className=cls;parent.append(span);parent=span;}
          parent.append(document.createTextNode(chunks[i]));
        }
      }
      function walk(node,classes){
        if(node.nodeType===Node.TEXT_NODE){append(node.nodeValue,classes);return;}
        if(node.nodeType!==Node.ELEMENT_NODE||node.tagName!=='SPAN'||node.attributes.length!==1||!node.hasAttribute('class')||!/^[-\w ]+$/.test(node.className))throw Error('unexpected highlighter token');
        for(const child of node.childNodes)walk(child,[...classes,node.className]);
      }
      for(const child of tpl.content.childNodes)walk(child,[]);
      if(line!==cells.length-1||fragments.some((f,i)=>f.textContent!==original[i]))return;
      cells.forEach((c,i)=>{c.replaceChildren(fragments[i]);c.classList.add('hljs-line');});
    }catch{/* plain escaped text remains available */}
  }
  for(const table of document.querySelectorAll('table[data-code-language]')){
    const lang=language(table.dataset.codeLanguage);
    const cells=[...table.querySelectorAll('.code-text')];
    if(cells.length)highlightCells(cells,lang);
    // Difference rows may be discontinuous; highlight each row independently.
    const diff=[...table.querySelectorAll('.diff-source')];
    if(diff.reduce((n,c)=>n+c.textContent.length,0)<=100000)for(const cell of diff)highlightCells([cell],lang);
  }
  for(const code of document.querySelectorAll('.markdown pre code[data-code-language]')){
    const lang=language(code.dataset.codeLanguage),source=code.textContent;
    if(!lang||!source||source.length>100000||source.split('\n').some(x=>x.length>8192))continue;
    try{
      const tpl=document.createElement('template');tpl.innerHTML=highlighter.highlight(source,{language:lang,ignoreIllegals:true}).value;
      if(tpl.content.textContent!==source)continue;
      if([...tpl.content.querySelectorAll('*')].some(n=>n.tagName!=='SPAN'||n.attributes.length!==1||!n.hasAttribute('class')||!/^[-\w ]+$/.test(n.className)))continue;
      code.replaceChildren(tpl.content);code.classList.add('hljs-line');
    }catch{}
  }
})();
