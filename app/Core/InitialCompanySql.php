<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * First-install-only, source-pinned company data import. This does not certify a
 * business identity or recruitment status and never publishes or sends email.
 * Requires the caller to own the same transaction that creates the developer.
 */
final class InitialCompanySql {
    private const TABLES = [
        'companies', 'project_seed_records', 'seed_record_states',
        'company_locations', 'business_change_events',
        'research_import_batches', 'research_import_items',
    ];

    public static function apply(PDO $db, int $initialDeveloperId): void {
        if(!$db->inTransaction() || $initialDeveloperId < 1)
            throw new \RuntimeException('First-install database transaction required');
        $root=SiteState::root();
        $manifestPath=$root.'/database/initial_company_data_manifest.json';
        $sqlPath=$root.'/database/initial_company_data.sql';
        // Read no more than a fixed budget; a tampered package must not exhaust
        // installer memory before its SHA256 can be checked.
        $manifestLimit=64*1024;
        $sqlLimit=2*1024*1024;
        $manifestRaw=@file_get_contents($manifestPath,false,null,0,$manifestLimit+1);
        $sql=@file_get_contents($sqlPath,false,null,0,$sqlLimit+1);
        if(!is_string($manifestRaw)||!is_string($sql))
            throw new \RuntimeException('Initial company data missing: installation cannot finish');
        if(strlen($manifestRaw)>$manifestLimit || strlen($sql)>$sqlLimit)
            throw new \RuntimeException('Initial company data exceeds the allowed package size');
        $m=json_decode($manifestRaw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($m)||($m['format']??null)!=='kmm-initial-company-sql-v1'
            ||($m['schema']??null)!==Migration::SCHEMA
            ||($m['policy']??null)!=='initial_install_only; private_drafts_and_held_research; no_auto_publication_or_notification'
            ||($m['statement_count']??null)!==130
            ||($m['counts']['candidate_locations']??null)!==2
            ||($m['counts']['draft_companies']??null)!==10
            ||($m['counts']['research_held']??null)!==27
            ||($m['counts']['link_held']??null)!==59
            ||($m['counts']['link_urls']??null)!==141
            ||($m['counts']['formal_company_id_approvals']??null)!==0
            ||!is_string($m['sql_sha256']??null)
            ||!hash_equals($m['sql_sha256'],hash('sha256',$sql)))
            throw new \RuntimeException('Initial SQL manifest mismatch');
        if(($m['sql_file']??null)!=='database/initial_company_data.sql'
            ||!is_array($m['pinned_sources']??null)
            ||count($m['pinned_sources'])!==3)
            throw new \RuntimeException('Initial SQL source list invalid');
        $expectedSources=[
            'database/seeds_company_2026-11-18.json',
            'docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json',
            'docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json',
        ];
        foreach($expectedSources as $path){
            $digest=$m['pinned_sources'][$path]??null;
            if(!is_string($digest)||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
                ||!is_file($root.'/'.$path)
                ||!hash_equals($digest,hash_file('sha256',$root.'/'.$path)))
                throw new \RuntimeException('Private research source has changed');
        }
        if(count($m['pinned_sources'])!==count($expectedSources))
            throw new \RuntimeException('Unexpected initial company data source');
        $q=$db->query('SELECT COUNT(*) FROM installation_state');
        if((int)$q->fetchColumn()!==0)
            throw new \RuntimeException('Initial company data cannot be applied to installed DB');
        // A half-populated destination must never be treated as a clean first install.
        // Table names are fixed class constants, not supplied by HTTP or the SQL bundle.
        foreach(self::TABLES as $table){
            $count=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            if($count!==0)throw new \RuntimeException('Initial company destination is not empty');
        }
        // The account is created in Migration::seed() in the same transaction.
        $q=$db->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? AND ur.role_code='DEVELOPER' AND u.status='active'");
        $q->execute([$initialDeveloperId]);
        if((int)$q->fetchColumn()!==1)
            throw new \RuntimeException('Initial developer not present');
        $lines=explode("\n",$sql);
        $executed=0;
        // SQL statements are generated one per line, string values hex encoded.
        // Fail closed on anything other than the fixed allowlisted INSERT shape.
        $db->exec('SET @kmm_initial_actor_id = '.(string)$initialDeveloperId);
        try {
            foreach($lines as $line){
                $line=trim($line);
                if($line==='' || str_starts_with($line,'-- '))continue;
                if(!preg_match('/\AINSERT INTO `([a-z_]+)` \(`(?:[a-z_][a-z0-9_]*`(?:,`[a-z_][a-z0-9_]*`)*)\) VALUES \(.+\);\z/D',$line,$matches)
                    ||!in_array($matches[1],self::TABLES,true)
                    ||substr_count($line,';')!==1)
                    throw new \RuntimeException('Unrecognized initial SQL statement');
                $db->exec($line);
                $executed++;
            }
        } finally {
            $db->exec('SET @kmm_initial_actor_id = NULL');
        }
        if($executed!==($m['statement_count']??null))
            throw new \RuntimeException('Initial company SQL truncated or incomplete');
    }
}
