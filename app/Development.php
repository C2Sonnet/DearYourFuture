<?php
declare(strict_types=1);
// Only explicitly named documents inside an approved, immutable public snapshot.
function developmentDocuments():array{return [
 'requirements'=>['要件定義','要件定義書','2026-11-18','requirements.pdf'],
 'requirements-spec'=>['要件定義','仕様書','2026-11-18','requirements-spec.pdf'],
 'basic-design'=>['基本設計','基本設計書','2026-11-18','basic-design.pdf'],
 'detailed-design'=>['詳細設計','詳細設計書','2026-11-19','detailed-design.pdf'],
 'test-spec'=>['テスト','テスト仕様書','2026-11-20','test-spec.pdf'],
 'test-report'=>['テスト','テスト報告書','2026-11-20','test-report.pdf'],
 'operations'=>['運用','機能説明書','2026-11-21','operations.pdf'],
 'maintenance'=>['保守','保守マニュアル','2026-11-21','maintenance.pdf'],
];}
function developmentPath(string $slug,array $files=[]):string{
 $item=developmentDocuments()[$slug]??throw new InvalidArgumentException('document');$approved=['requirements'=>'resources/private/waterfall/キミの未来へ_要件定義_要件定義書.pdf','requirements-spec'=>'resources/private/waterfall/キミの未来へ_要件定義_仕様書.pdf','basic-design'=>'resources/private/waterfall/キミの未来へ_基本設計_基本設計書.pdf','detailed-design'=>'resources/private/waterfall/キミの未来へ_詳細設計_詳細設計書.pdf','test-spec'=>'resources/private/waterfall/キミの未来へ_テスト_テスト仕様書.pdf','test-report'=>'resources/private/waterfall/キミの未来へ_テスト_テスト報告書.pdf','operations'=>'resources/private/waterfall/キミの未来へ_運用_機能説明書.pdf','maintenance'=>'resources/private/waterfall/キミの未来へ_保守_保守マニュアル.pdf']; $path=$approved[$slug];return isset($files[$path])?$path:'docs/development/'.$item[3];
}
function developmentPage(string $v,array $files):string{
 $out='<section class="development-hero panel"><h2>開発資料・ウォーターフォール</h2><p>文書に記載された工程日付に沿って表示しています。実際の作業開始日・検収実績とは区別してください。</p><p class="muted">工程・文書一覧の出典：公開ソース v2.6.1 / v3.0.0 の開発資料画面（工程・文書一覧一致）。閲覧対象：'.h($v).'。PDFは2026年11月18〜21日付の歴史的文書です。v3.0.0の実測試験報告ではありません。</p></section>';
 $out.='<section class="panel development-section"><h2>文書工程の進捗</h2><p><strong>100%</strong>（原画面の文書工程表示。実機受入・本番公開承認を意味しません。）</p><progress class="development-progress" value="100" max="100" aria-label="文書工程の表示上の進捗">100%</progress><h2>開発の流れ</h2><p class="muted">起点：2026年11月18日（要件定義の文書日付）。人日は1人×経過日数の仮定による換算で、実測工数ではありません。</p><ol class="development-flow">';
 foreach([['要件定義・基本設計','11/18','1'],['詳細設計・実装工程','11/19','2'],['テスト','11/20','3'],['運用・保守','11/21','4']] as [$label,$date,$day])$out.='<li><strong>'.h($label).'</strong><span>'.h($date).' ・ '.h($day).'日目 ・ '.h($day).'人日</span></li>';
 $out.='</ol></section><section class="development-section panel"><h2>工程別の文書一覧</h2><p class="muted">元サイトの開発者画面とは独立し、公開承認済みのPDFのみ、このリポジトリの指定パスからダウンロードできます。未収録の資料にはリンクを表示しません。</p>';
 $group='';foreach(developmentDocuments() as $slug=>$item){if($group!==$item[0]){if($group!=='')$out.='</div>'; $group=$item[0];$out.='<h3>'.h($group).'</h3><div class="development-docs">';}$day=(int)(new DateTimeImmutable('2026-11-18'))->diff(new DateTimeImmutable($item[2]))->days+1;$path=developmentPath($slug,$files);$out.='<article class="development-document"><h4>'.h($item[1]).'</h4><p>文書日付：'.h($item[2]).' ／ '.$day.'日目 ／ '.$day.'人日（換算）</p>'.(isset($files[$path])?linkto('PDFをダウンロード','development-pdf',['v'=>$v,'document'=>$slug]):'<p class="muted">公開用PDF未収録</p>').'</article>';}
 $out.='</div><p class="muted">原画面では2026年11月23日にDOCX正本をPDFに変換して固定保存。実装工程には指定のPDF正本がありません。</p></section><section class="panel development-section"><h2>公開リポジトリ内の資料</h2><ul>';
 foreach(['README.md'=>'概要','docs/README.md'=>'リポジトリ内ドキュメント','docs/USER_GUIDE.md'=>'一般ユーザー向け操作ガイド','docs/DATA_AND_EXPORT.md'=>'情報導入・出力・データ保護','docs/THIRD_PARTY_NOTICES.md'=>'第三者素材・原典に関する注意','INSTALL.md'=>'インストール・更新','CHANGELOG.md'=>'変更履歴','SECURITY.md'=>'セキュリティ方針'] as $path=>$label){if(isset($files[$path]))$out.='<li>'.linkto($label,'file',['v'=>$v,'path'=>$path,'view'=>'rendered']).'</li>';}
 return $out.'</ul></section>';
}
