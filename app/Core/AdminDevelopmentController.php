<?php
declare(strict_types=1);
namespace KMM\Core;
/** Immutable PDFs created once from the waterfall DOCX source snapshots. */
final class AdminDevelopmentController {
    private const DOCUMENTS=[
        'requirements'=>['要件定義','要件定義書','2026-11-18','キミの未来へ_要件定義_要件定義書.pdf'],
        'requirements-spec'=>['要件定義','仕様書','2026-11-18','キミの未来へ_要件定義_仕様書.pdf'],
        'basic-design'=>['基本設計','基本設計書','2026-11-18','キミの未来へ_基本設計_基本設計書.pdf'],
        'detailed-design'=>['詳細設計','詳細設計書','2026-11-19','キミの未来へ_詳細設計_詳細設計書.pdf'],
        'test-spec'=>['テスト','テスト仕様書','2026-11-20','キミの未来へ_テスト_テスト仕様書.pdf'],
        'test-report'=>['テスト','テスト報告書','2026-11-20','キミの未来へ_テスト_テスト報告書.pdf'],
        'operations'=>['運用','機能説明書','2026-11-21','キミの未来へ_運用_機能説明書.pdf'],
        'maintenance'=>['保守','保守マニュアル','2026-11-21','キミの未来へ_保守_保守マニュアル.pdf'],
    ];
    public static function isRoute(string $path):bool{return $path==='/admin/development'||preg_match('~\A/admin/development/pdf/[a-z0-9-]+\z~D',$path)===1;}
    public static function handle(string $path,string $method):void {
        if($method!=='GET'){header('Allow: GET');http_response_code(405);return;}
        if(str_starts_with($path,'/admin/development/pdf/')){
            $slug=substr($path,strlen('/admin/development/pdf/'));
            if(!isset(self::DOCUMENTS[$slug])){http_response_code(404);return;}
            $folder=SiteState::root().'/resources/private/waterfall';
            $f=$folder.'/'.self::DOCUMENTS[$slug][3];
            if(!is_file($f)||is_link($f)||!is_readable($f)){http_response_code(404);return;}
            header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$slug.'.pdf"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
            readfile($f);return;
        }
        $html='<section class="hero compact"><span class="eyebrow">DEVELOPMENT / ADMIN</span><h1>開発資料・ウォーターフォール</h1><p>文書に記載された工程日付に沿って表示しています。実際の作業開始日・検収実績とは区別してください。</p></section>';
        $html.='<section class="section"><h2>文書工程の進捗</h2><div class="waterfall-progress-label"><strong>100%</strong><span>文書工程の表示上の進捗。実機の受入・本番公開承認を意味しません。</span></div><progress value="100" max="100" class="waterfall-progress">100%</progress></section>';
        $source="flowchart LR\n  A[\"要件定義・基本設計\\n11/18 · 1日目 · 1人日\"] --> B[\"詳細設計・実装工程\\n11/19 · 2日目 · 2人日\"]\n  B --> C[\"テスト\\n11/20 · 3日目 · 3人日\"]\n  C --> D[\"運用・保守\\n11/21 · 4日目 · 4人日\"]";
        $html.='<section class="section"><h2>開発の流れ</h2><p class="note">起点：2026年11月18日（要件定義の文書日付）。人日は「1人×経過日数」の仮定による換算値です。実測工数ではありません。</p><div class="r06-mermaid-render" data-admin-mermaid><div data-admin-mermaid-output></div><pre data-admin-mermaid-source class="mermaid-fallback">'.Security::h($source).'</pre></div><script type="module" src="/assets/admin-development.js"></script></section>';
        $group='';foreach(self::DOCUMENTS as $slug=>$item){
            if($item[0]!==$group){if($group!=='')$html.='</div></section>';$group=$item[0];$html.='<section class="section"><h2>'.Security::h($group).'</h2><div class="waterfall-pdf-list">';}
            $day=(int)(new \DateTimeImmutable('2026-11-18'))->diff(new \DateTimeImmutable($item[2]))->days+1;
            $html.='<article class="panel"><h3>'.Security::h($item[1]).'</h3><p>文書日付：'.Security::h($item[2]).' ／ 開発開始から'.$day.'日目 ／ '.$day.'人日（換算）</p><a class="btn ghost" href="/admin/development/pdf/'.Security::h($slug).'" target="_blank" rel="noopener noreferrer">PDFを表示</a></article>';
        }if($group!=='')$html.='</div></section>';
        $html.='<section class="section"><p class="note">書類は2026年11月23日にウォーターフォール書類のDOCX正本からPDFへ変換して固定保存しました。以後の自動更新は行いません。実装工程には指定のPDF正本がないため空欄です。</p></section>';
        Kernel::layout('開発資料',$html);
    }
}
