<?php
declare(strict_types=1);
/** CLI-only, dry-run by default. Does not overwrite edits or publish research snapshots. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{BusinessData,Database,SiteState};
if(count($argv)>2 || (isset($argv[1])&&!in_array($argv[1],['--dry-run','--apply-initial-only'],true))){fwrite(STDERR,"Usage: php tools/seeds/import-candidates.php [--dry-run|--apply-initial-only]\n");exit(2);}
$apply=($argv[1]??'--dry-run')==='--apply-initial-only';
$data=json_decode((string)file_get_contents(dirname(__DIR__,2).'/database/seeds_company_2026-11-18.json'),true,512,JSON_THROW_ON_ERROR);
if(($data['schema']??null)!==1 || !is_array($data['companies']??null)||($data['publication_rule']??'')!=='initial_drafts_only; independent source review required')throw new RuntimeException('Unrecognized seed file');
if(!preg_match('/\A[a-z0-9-]{3,50}\z/D',(string)$data['seed_version']))throw new RuntimeException('Bad seed version');
$seen=[];$normalized=[];
foreach($data['companies'] as $c){
    $key=(string)($c['key']??'');if(!preg_match('/\A[a-z0-9-]{2,50}\z/D',$key)||isset($seen[$key]))throw new RuntimeException('Invalid or duplicated seed key');$seen[$key]=true;
    $name=BusinessData::text($c['display_name']??null,240,true);
    $note=BusinessData::text($c['note']??'',6000);
    $locations=[];
    foreach(($c['locations']??[]) as $loc){$row=BusinessData::fields('company_locations',$loc);BusinessData::requireProvenance('company_locations',$row);$locations[]=$row;}
    $normalized[]=['key'=>'kmm-research-'.$key,'name'=>$name,'note'=>$note,'locations'=>$locations];
}
if(!$apply){echo 'DRY RUN: '.count($normalized)." companies; all imported as private admin drafts, no job status promoted.\n";foreach($normalized as $row)echo ' - '.$row['name'].' ('.count($row['locations'])." candidate locations)\n";exit(0);}
if(SiteState::state()!=='ready')throw new RuntimeException('Install before seed import');
$db=Database::connected();$inserted=0;$skipped=0;
$db->beginTransaction();
try{
    foreach($normalized as $row){
        $stable=hash('sha256',$row['key']);$id=substr($stable,0,32);
        $q=$db->prepare('SELECT id FROM companies WHERE seed_key=?');$q->execute([$row['key']]);
        if($q->fetchColumn()){++$skipped;continue;}
        // No update path: later seed revisions require controlled 3-way merge, never silently overwrite.
        $q=$db->prepare("INSERT INTO companies(id,display_name,description,visibility,seed_key) VALUES (?,?,?,'draft',?)");$q->execute([$id,$row['name'],$row['note'],$row['key']]);
        $db->prepare('INSERT INTO project_seed_records(seed_key,company_id,source_document,source_checked_on,seed_version) VALUES (?,?,?,?,?)')
          ->execute([$row['key'],$id,$data['source_document'],$data['source_checked_on'],$data['seed_version']]);
        $baseline=\KMM\Core\SeedData::snapshot(['display_name'=>$row['name'],'legal_name'=>null,'description'=>$row['note'],'website_url'=>null]);
        $db->prepare('INSERT INTO seed_record_states(seed_key,company_id,base_json,seed_version,source_document,source_checked_on) VALUES (?,?,?,?,?,?)')
          ->execute([$row['key'],$id,\KMM\Core\SeedData::encode($baseline),$data['seed_version'],$data['source_document'],$data['source_checked_on']]);
        foreach($row['locations'] as $l){
            $l['company_id']=$id;$lid=BusinessData::id();$names=array_keys($l);
            $db->prepare('INSERT INTO company_locations(id,'.implode(',',$names).') VALUES (?,'.implode(',',array_fill(0,count($names),'?')).')')->execute(array_merge([$lid],array_values($l)));
        }
        $db->prepare('INSERT INTO business_change_events(actor_id,record_type,record_id,operation,after_json) VALUES (NULL,?,?,?,?)')
          ->execute(['companies',$id,'seed_draft_added',json_encode(['seed_key'=>$row['key'],'snapshot_date'=>$data['source_checked_on']],JSON_THROW_ON_ERROR)]);
        ++$inserted;
    }
    $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
echo "Imported $inserted unpublished candidates, skipped $skipped previously seeded records. No production fields overwritten.\n";
