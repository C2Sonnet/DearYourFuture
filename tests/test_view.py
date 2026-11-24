import pathlib,subprocess,unittest,json,re,html
ROOT=pathlib.Path(__file__).resolve().parents[1]
class ViewTests(unittest.TestCase):
 def render(self,source,file='README.md',version='v1.0.0'):
  expr='require "app/View.php"; $d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo markdown($d[0],$d[1],$d[2],$d[3]);'
  return subprocess.run(['php','-r',expr],cwd=ROOT,input=json.dumps([source,version,file,{'README.md':{},'docs/guide.md':{},'public/hero.png':{},'app.php':{}}],ensure_ascii=False),text=True,capture_output=True,check=True).stdout
 def test_markdown_structures(self):
  s=self.render('# 見出し\n## 見出し\n\n[移動](#見出し)\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\n1. a\n2. b\n\n> note\n\n```php\n<script>\n```\n\n**bold** *em* ~~del~~')
  ids=re.findall(r'<h[1-6] id="([^"]+)"',s);self.assertEqual(len(set(ids)),2);self.assertIn('href="#'+ids[0]+'"',s);self.assertIn('toc-depth-2',s);self.assertIn('<th scope="col">A</th>',s);self.assertIn('<ol start="1">',s);self.assertIn('<blockquote>note</blockquote>',s);self.assertIn('&lt;script&gt;',s);self.assertIn('<strong>bold</strong> <em>em</em> <del>del</del>',s)
 def test_links_and_safety(self):
  s=self.render('[guide](docs/guide.md#hello) ![hero](public/hero.png) [code](app.php#L9) [bad](javascript:alert(1)) ![remote](https://example.com/p.png) [escape](../../README.md) <img src=x onerror=alert(1)> `[no](https://example.com)`')
  self.assertIn('view=rendered',s);self.assertIn('page=image',s);self.assertIn('line=9#L9',s);self.assertNotIn('href="javascript:',s);self.assertNotIn('src="https:',s);self.assertIn('&lt;img src=x',s);self.assertIn('<code>[no](https://example.com)</code>',s);self.assertEqual(s.count('<a '),2)
 def test_distinct_release_tocs(self):
  a=self.render('# Same',version='v1.0.0');b=self.render('# Same',version='v1.0.1');self.assertTrue(set(re.findall(r' id="([^"]+)"',a)).isdisjoint(re.findall(r' id="([^"]+)"',b)))
 def test_commit_provenance_and_language(self):
  s=subprocess.run(['php','-r','require "app/View.php"; echo commitPopover(["id"=>"abc","upstream_commit"=>"693752f","parent"=>null,"message"=>"<test>","date"=>"2026-11-24","tag"=>"v2.6.1"],["owner"=>"C2Sonnet"]); echo languageView(["PHP"=>9999,"HTML"=>1]);'],cwd=ROOT,text=True,capture_output=True,check=True).stdout
  self.assertIn('143,415',s);self.assertIn('未再計算',s);self.assertIn('&lt;test&gt;',s);self.assertIn('0.1%未満',s);self.assertIn('Linguistとは異なります',s);self.assertIn('width="10.000000"',s)
if __name__=='__main__':unittest.main()
