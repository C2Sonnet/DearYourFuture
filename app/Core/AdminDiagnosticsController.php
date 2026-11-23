<?php
declare(strict_types=1);
namespace KMM\Core;

/** Developer-only *read-only* operational status: never leaks configuration or starts an update. */
final class AdminDiagnosticsController {
    public const ROUTES=['/admin/system/diagnostics','/admin/system/updater','/admin/system/recovery'];
    public static function isRoute(string $path):bool{return in_array($path,self::ROUTES,true);}
    public static function mayView(string $role):bool{return Rbac::canTechnicalSettings($role);}
    public static function checks():array {
        return [
            ['PHPランタイム',PHP_VERSION,version_compare(PHP_VERSION,'8.5','>=')],
            ['PDO MySQL拡張',extension_loaded('pdo_mysql')?'存在':'未導入',extension_loaded('pdo_mysql')],
            ['OpenSSL',extension_loaded('openssl')?'存在':'未導入',extension_loaded('openssl')],
            ['Sodium暗号',extension_loaded('sodium')?'存在':'未導入',extension_loaded('sodium')],
            ['Argon2id',defined('PASSWORD_ARGON2ID')?'利用可能':'未導入',defined('PASSWORD_ARGON2ID')],
            ['現在のSchema実装', (string)SchemaUpgrade::TARGET,true],
            ['本番用リリース署名','オフライン運用時に別途検証',false],
        ];
    }
    public static function handle(string $path,string $method,array $user):void {
        if(!self::mayView((string)$user['role'])){http_response_code(403);Kernel::layout('権限がありません','<p>開発者以外は技術診断へアクセスできません。</p>',false);return;}
        if($method!=='GET'){http_response_code(405);header('Allow: GET');Kernel::layout('許可されない操作','<p>技術診断は閲覧専用です。</p>',false);return;}
        $html='<section class="hero compact"><span class="eyebrow">DEVELOPER ONLY</span><h1>技術診断・更新・復旧</h1><p>安全上、ブラウザから本番更新や復元を直接実行できません。署名済みリリースと検証済みバックアップを用いた保守CLIで管理します。</p></section>';
        $html.='<nav class="app-subnav"><a href="/admin/system/diagnostics">環境状態</a><a href="/admin/system/updater">更新手順</a><a href="/admin/system/recovery">復旧手順</a><a href="/admin/system/mail">SMTP設定</a></nav>';
        if($path==='/admin/system/diagnostics'){
            $html.='<section class="section"><h2>安全な環境診断</h2><p class="note">DB接続先、認証情報、秘密鍵、フルパス、メッセージ本文は表示しません。</p>';
            foreach(self::checks() as [$label,$value,$pass])$html.='<div class="check"><span>'.Security::h($label).'</span><strong>'.Security::h((string)$value).'</strong><small>'.($pass?'検出':'要確認').'</small></div>';
            $items=OperationalPreflight::collect();$counts=OperationalPreflight::totals($items);
            $html.='<h3>非破壊の更新前チェック</h3><p class="note">内部ゲート '.(int)$counts['PASS'].' PASS / '.(int)$counts['BLOCK'].' BLOCK。外部条件 '.(int)$counts['DEFERRED'].' 件は最後の一括確認まで未受入です。ここではDBに接続せず、秘密情報・フルパス・設定値を表示しません。</p>';
            foreach($items as $entry){$status=$entry['status'];$summary=$status==='PASS'?'内部条件を確認':($status==='BLOCK'?'内部要対応':'外部一括確認待ち');
                $html.='<div class="check"><span>'.Security::h($entry['label']).'</span><strong>'.Security::h($summary).'</strong><small>'.Security::h($entry['scope']).'</small></div>';
            }
            $html.='</section>';
        }elseif($path==='/admin/system/updater'){
            $html.='<section class="section"><h2>安全な更新手順</h2><p>更新前に、署名とハッシュ、稼働版マニフェスト、DBのバックアップ、保守ロック、Migration差分を確認します。各条件を満たさない場合は更新を拒否します。</p><p>更新CLIの検証器と手順書は開発レポジトリの <code>tools/updater/release.py</code> と <code>docs/UPDATE_AND_RECOVERY.md</code> に保存されています。</p><p class="note">この管理画面には更新実行ボタンを置いていません。外部一括検証が終わるまで更新を本番運用に使用しないでください。</p></section>';
        }else{
            $html.='<section class="section"><h2>バックアップ・復旧手順</h2><p>DB、非公開ライブラリ、構成ファイル、旧コードの整合性を確認します。復元は独立したテスト環境で成功したもののみ採用してください。</p><p>保守アーカイブは管理CLIでのみ扱います。復旧中のロック状態と復旧先DB名を事前確認し、既存データを無条件に上書きしません。</p><p class="note">MySQL実DBバックアップ・復元の受入は、最後の外部一括検証へ延期しています。</p></section>';
        }
        Kernel::layout('技術診断・復旧',$html,false);
    }
}
