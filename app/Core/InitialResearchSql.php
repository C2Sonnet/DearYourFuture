<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/**
 * Only for a fresh installation, after the pinned Seed10+research corpus.
 * Imports private R06 companies/jobs within Migration::seed()'s transaction.
 * No DDL, no publication, no recruitment confirmation and no notifications.
 */
final class InitialResearchSql {
    private const INSERT_TABLES = [
        'companies', 'brands', 'sources', 'company_locations',
        'jobs', 'job_locations', 'company_sources', 'seed_entity_states',
    ];
    private const MUST_START_EMPTY = [
        'jobs', 'sources', 'brands', 'job_locations',
        'company_sources', 'seed_entity_states',
    ];
    private const SOURCE_FILES = [
        'docs/CHANGE25_BATCH20_NORMALIZED_R06.json',
        'docs/research_snapshot_d1.0.4_R06/COMPANY_JOB_RELATIONS_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/DATA_MANIFEST_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/RESEARCH_DELTA_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/JOB_EMPLOYMENT_TYPES_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/JOB_SCREENING_MATRIX_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/PUBLIC_SOURCE_CHECKS_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/RESEARCH_AUDIT_d1.0.4_R06.md',
    ];
    private const EXPECTED_SEED_RELATIONS = [
        'CO-0024'=>'ph-paris-japon', 'CO-0012'=>'ukai', 'CO-0020'=>'tops',
        'CO-0014'=>'edelweiss','CO-0015'=>'quatre','CO-0009'=>'raise',
        'CO-0038'=>'marefox','CO-0042'=>'peterpan','CO-0026'=>'pompadour',
        'CO-0025'=>'asanoya',
    ];

    public static function apply(PDO $db, int $developerId): void {
        if(!$db->inTransaction() || $developerId < 1)
            throw new \RuntimeException('Initial R06 data requires first-install transaction');
        $root=SiteState::root();
        $manifestRaw=@file_get_contents($root.'/database/initial_research_r06_manifest.json',false,null,0,65537);
        $sql=@file_get_contents($root.'/database/initial_research_r06.sql',false,null,0,4194305);
        if(!is_string($manifestRaw) || !is_string($sql) || strlen($manifestRaw)>65536 || strlen($sql)>4194304)
            throw new \RuntimeException('Initial R06 package missing or oversized');
        $m=json_decode($manifestRaw,true,24,JSON_THROW_ON_ERROR);
        if(!is_array($m)
            ||($m['format']??null)!=='kmm-initial-research-sql-v1'
            ||($m['schema']??null)!==Migration::SCHEMA
            ||($m['source_release']??null)!=='d1.0.4 R06'
            ||($m['policy']??null)!=='initial_install_only; private_unverified_R06_business_jobs; no_closed_jobs; no_auto_publication'
            ||($m['sql_file']??null)!=='database/initial_research_r06.sql'
            ||!is_string($m['sql_sha256']??null)
            ||!hash_equals($m['sql_sha256'],hash('sha256',$sql))
            ||($m['counts']['canonical_company_total']??null)!==103
            ||($m['counts']['companies_new']??null)!==93
            ||($m['counts']['companies_seed10_updated']??null)!==10
            ||($m['counts']['jobs']??null)!==128
            ||($m['counts']['private_entity_states']??null)!==231
            ||($m['counts']['explicitly_closed_not_imported']??null)!==8
            ||($m['counts']['status_conflicted_not_imported']??null)!==1
            ||($m['statement_count']??null)!==960
            ||($m['fixed_seed_relations']??null)!==self::EXPECTED_SEED_RELATIONS)
            throw new \RuntimeException('Initial R06 SQL manifest mismatch');

        $sources=$m['pinned_sources']??null;
        if(!is_array($sources)||count($sources)!==count(self::SOURCE_FILES))
            throw new \RuntimeException('Initial R06 source pin list invalid');
        foreach(self::SOURCE_FILES as $path){
            $digest=$sources[$path]??null;
            if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)
                ||!is_file($root.'/'.$path)
                ||!hash_equals($digest,hash_file('sha256',$root.'/'.$path)))
                throw new \RuntimeException('Initial R06 research provenance changed');
        }
        if(!is_string($m['original_seed10_sha256']??null)
            ||!is_file($root.'/database/seeds_company_2026-11-18.json')
            ||!hash_equals($m['original_seed10_sha256'],hash_file('sha256',$root.'/database/seeds_company_2026-11-18.json')))
            throw new \RuntimeException('Original Seed10 provenance changed');

        if((int)$db->query('SELECT COUNT(*) FROM installation_state')->fetchColumn()!==0)
            throw new \RuntimeException('Cannot add initial R06 data after installation');
        if((int)$db->query('SELECT COUNT(*) FROM project_seed_records')->fetchColumn()!==10
            ||(int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==10)
            throw new \RuntimeException('Expected original Seed10 not present');
        foreach(self::MUST_START_EMPTY as $table){
            if((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)
                throw new \RuntimeException('Initial R06 destination already populated');
        }
        $q=$db->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=? AND ur.role_code='DEVELOPER' AND u.status='active'");
        $q->execute([$developerId]);
        if((int)$q->fetchColumn()!==1)
            throw new \RuntimeException('Developer missing before R06 import');

        $executed=0;
        $updates=0;
        foreach(explode("\n",$sql) as $line){
            $line=trim($line);
            if($line===''||str_starts_with($line,'-- '))continue;
            if(substr_count($line,';')!==1 || !str_ends_with($line,';'))
                throw new \RuntimeException('Invalid R06 SQL line terminator');
            if(preg_match('/\AINSERT INTO `([a-z_]+)` \(`(?:[a-z_][a-z0-9_]*`(?:,`[a-z_][a-z0-9_]*`)*)\) VALUES \(.+\);\z/D',$line,$match)){
                if(!in_array($match[1],self::INSERT_TABLES,true))
                    throw new \RuntimeException('Unexpected initial R06 destination table');
                $db->exec($line);
            } elseif(preg_match('/\AUPDATE `companies` SET `display_name`=.+,`legal_name`=.+,`description`=.+,`website_url`=.+ WHERE `id`=.+ AND `seed_key`=.+ AND `visibility`=\'draft\';\z/D',$line)){
                $changed=$db->exec($line);
                if($changed!==1)throw new \RuntimeException('Original Seed10 identity cannot be reconciled');
                $updates++;
            } else {
                throw new \RuntimeException('Unrecognized initial R06 SQL statement');
            }
            $executed++;
        }
        if($executed!==960 || $updates!==10)
            throw new \RuntimeException('Initial R06 SQL is incomplete');
    }
}
