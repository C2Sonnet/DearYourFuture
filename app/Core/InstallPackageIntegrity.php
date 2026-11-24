<?php
declare(strict_types=1);
namespace KMM\Core;
/** Read-only package provenance gate. It runs before the first persistent installer write. */
final class InstallPackageIntegrity {
    public const PINNED_FILES = [
        'database/seeds_company_2026-11-18.json',
        'docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json',
        'docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json',
        'docs/CHANGE25_BATCH20_NORMALIZED_R06.json',
        'docs/research_snapshot_d1.0.4_R06/COMPANY_JOB_RELATIONS_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/DATA_MANIFEST_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/RESEARCH_DELTA_d1.0.4_R06.json',
        'docs/research_snapshot_d1.0.4_R06/JOB_EMPLOYMENT_TYPES_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/JOB_SCREENING_MATRIX_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/PUBLIC_SOURCE_CHECKS_d1.0.4_R06.csv',
        'docs/research_snapshot_d1.0.4_R06/RESEARCH_AUDIT_d1.0.4_R06.md',
    ];
    /** @return array{ok:bool,missing:list<string>,mismatch:list<string>,invalid:list<string>,checked:int} */
    public static function inspect(?string $root=null): array {
        $root=$root ?? SiteState::root();
        $missing=[];$mismatch=[];$invalid=[];$checked=0;
        foreach([
            ['database/initial_company_data_manifest.json','database/initial_company_data.sql',array_slice(self::PINNED_FILES,0,3)],
            ['database/initial_research_r06_manifest.json','database/initial_research_r06.sql',array_slice(self::PINNED_FILES,3)],
        ] as [$manifestPath,$sqlPath,$expectedPins]) {
            $raw=@file_get_contents($root.'/'.$manifestPath);
            $sqlPathFull=$root.'/'.$sqlPath;
            if(!is_string($raw)){ $missing[]=$manifestPath; continue; }
            $manifest=json_decode($raw,true);
            if(!is_array($manifest) || !is_array($manifest['pinned_sources']??null)){
                $invalid[]=$manifestPath; continue;
            }
            $pinned=$manifest['pinned_sources'];
            $actualKeys=array_keys($pinned); sort($actualKeys);
            $expectedKeys=$expectedPins; sort($expectedKeys);
            if($actualKeys!==$expectedKeys) $invalid[]=$manifestPath.':pin_set';
            if(!is_file($sqlPathFull)) $missing[]=$sqlPath;
            elseif(!is_string($manifest['sql_sha256']??null)
                || !hash_equals($manifest['sql_sha256'],hash_file('sha256',$sqlPathFull))) $mismatch[]=$sqlPath;
            else $checked++;
            foreach($expectedPins as $path){
                $disk=$root.'/'.$path;
                if(!is_file($disk)){$missing[]=$path;continue;}
                $digest=$pinned[$path]??null;
                if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)
                   || !hash_equals($digest,hash_file('sha256',$disk)))$mismatch[]=$path;
                else $checked++;
            }
        }
        return ['ok'=>!$missing&&!$mismatch&&!$invalid,
            'missing'=>$missing,'mismatch'=>$mismatch,'invalid'=>$invalid,'checked'=>$checked];
    }
}
