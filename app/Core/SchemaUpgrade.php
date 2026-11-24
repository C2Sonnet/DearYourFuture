<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;

/** v1-v14 additive schema path. Never DROP or ALTER an existing table. */
final class SchemaUpgrade {
    public const TARGET = 41;
    public const STEPS = [
        1 => ['installation_state','schema_migrations','system_settings','users','roles','user_roles','audit_logs'],
        2 => ['auth_tokens','user_profiles','mail_delivery_events'],
        3 => ['companies','sources','brands','company_locations','jobs','job_locations','company_sources','business_change_events','project_seed_records'],
        4 => ['library_documents','library_document_versions'],
        5 => ['application_tracks','application_events','application_audit'],
        6 => ['edit_proposals','public_change_events'],
        7 => ['messages','message_recipients','message_reports','message_user_settings','message_blocks','message_broadcasts','message_delivery_jobs'],
        8 => ['seed_record_states','seed_user_overlays','seed_update_batches','seed_merge_conflicts','seed_batch_effects','seed_admin_notices','history_import_batches','history_import_items'],
        9 => [],
        10 => [],
        11 => ['seed_entity_states','seed_entity_overlays','seed_entity_conflicts','message_sender_states'],
        12 => [], // Internal hardening release: no DDL changes
        13 => ['user_favorite_companies','user_company_notes','user_saved_filters','user_guide_steps'],
        14 => ['user_job_eligibility_checks','library_document_metadata','company_fact_claims','user_reminder_settings','user_reminder_deliveries'],
        15 => [], // Internal admin validation, preflight and usability: metadata-only upgrade
        16 => [], // Internal transaction and ownership hardening: metadata-only upgrade
        17 => [], // Broadcast lifecycle, role revocation and public evidence hardening: metadata-only upgrade
        18 => [], // Atomic visible-page review and offline failure/race safety: metadata-only upgrade
        19 => [], // Composite seed rollback, interrupted installer guard and invitation retry: metadata-only upgrade
        20 => [], // Single-flight invitation retry, composite second-stage failure and revocable library stream: metadata-only upgrade
        21 => [], // Guard against generic activation during incomplete invitation recovery: metadata-only upgrade
        22 => [], // Operator-confirmed ambiguous invitation recovery + reviewed composite retry: metadata-only
        23 => [], // Atomic reminder audit, shared lifecycle mock and reviewed reimport admin workflow: metadata-only
        24 => [], // Account-locked direct messages, audited user settings and private application state: metadata-only
        25 => [], // Private application event write locks and one-use reviewed import preview cancellation: metadata-only
        26 => [], // Research d1.0.1 integrity-bound review-only ingestion: metadata-only
        27 => [], // Research d1.0.1 seed identity candidate audit: read-only, no DDL
        28 => [], // Operator evidence preflight (unapproved / offline): no DDL
        29 => [], // Authenticated research preflight / audit only; no DDL
        30 => [], // Audited research evidence return / status reconciliation: no DDL
        31 => ['research_identity_review_states'], // Internal evidence item lifecycle with optimistic concurrency
        32 => [], // Fresh install review table parity and read-only cross-admin queue: metadata-only
        33 => [], // Read-only, transaction-bound 3-way draft comparison; no DDL
        34 => [], // Read-only, revision-checked cross-admin audit history; no DDL
        35 => [], // Research division trust handoff, site feed and snapshot; no DDL
        36 => [], // Private accepted-feed mapping and admin technical preview; no DDL
        37 => ['research_import_batches','research_import_items','research_import_outbox'], // Private atomic import and held outbox
        38 => ['research_external_links'], // Pinned d1.0.2 categorized external link catalog with explicit public mapping
        39 => ['research_supplement_claims'], // Additional source-grounded company/job claims, private by default
        40 => ['research_publication_batches','research_publication_outbox'], // Permissioned publication ledger; held outbox only
        41 => ['research_link_revision_guards'], // Additive link publication revision fence
    ];

    /** @return array<string,string> table => CREATE statement */
    public static function definitions(): array {
        $all = array_merge(Migration::statements(), BusinessSchema::statements(), LibrarySchema::statements(), ApplicationSchema::statements(), ProposalSchema::statements(), MessageSchema::statements(), SeedSchema::statements(), SeedEntitySchema::statements(),MessageSenderSchema::statements(),WorkspaceSchema::statements(),EnrichmentSchema::statements(),ResearchIdentityReviewSchema::statements(),ResearchImportSchema::statements(),ResearchExternalLinkSchema::statements(),ResearchSupplementSchema::statements(),ResearchPublicationSchema::statements(),ResearchLinkRevisionSchema::statements());
        $map=[];
        foreach ($all as $sql) {
            if (!preg_match('/\ACREATE TABLE IF NOT EXISTS ([a-z0-9_]+)\s*\(/i', $sql, $m)) throw new \LogicException('Unrecognized schema declaration');
            if (isset($map[$m[1]])) throw new \LogicException('Duplicate schema declaration');
            $map[$m[1]] = $sql;
        }
        return $map;
    }
    public static function additions(int $from): array {
        if ($from < 1 || $from > self::TARGET) throw new \InvalidArgumentException('Unsupported prior schema version');
        $result=[];
        foreach (self::STEPS as $version => $tables) if ($version > $from) $result=array_merge($result,$tables);
        return $result;
    }
    public static function historicTables(int $version): array {
        if ($version < 1 || $version > self::TARGET) throw new \InvalidArgumentException('Unsupported schema version');
        $result=[];
        foreach (self::STEPS as $v=>$tables) if ($v <= $version) $result=array_merge($result,$tables);
        return $result;
    }
    public static function verifyDeclarations(): void {
        $names = array_keys(self::definitions());
        $historic=self::historicTables(self::TARGET);
        if (count($names)!==count($historic) || array_diff($names,$historic) || array_diff($historic,$names)) throw new \LogicException('Upgrade table inventory differs from current DDL');
    }
    /** Validate the signed target's release/schema metadata; do not trust a directory name. */
    public static function targetRelease():string {
        $release=trim((string)@file_get_contents(SiteState::root().'/VERSION'));
        $manifest=json_decode((string)@file_get_contents(SiteState::root().'/release-manifest.json'),true);
        if(!preg_match('/\A0\.219\.([1-9][0-9]*)-beta\z/D',$release)
            ||!is_array($manifest)||($manifest['version']??null)!==$release
            ||($manifest['schema_version']??null)!==self::TARGET){
            throw new \LogicException('Release/schema metadata mismatch');
        }
        return $release;
    }
    /** Old 0.1.x schema releases and older same-schema beta releases only. */
    public static function sourceSupported(int $schema,string $release):bool {
        if($schema<1||$schema>self::TARGET)return false;
        if($schema<self::TARGET)return $release==='0.1.'.$schema;
        if($release==='0.1.'.self::TARGET)return true;
        if(!preg_match('/\A0\.219\.([1-9][0-9]*)-beta\z/D',$release,$old))return false;
        $target=self::targetRelease();
        if(!preg_match('/\A0\.219\.([1-9][0-9]*)-beta\z/D',$target,$current))return false;
        return (int)$old[1]<(int)$current[1];
    }
    /** Consecutive additive stamps for old schemas; metadata-only updates add none. */
    public static function migrationVersions(int $from):array {
        if($from<1||$from>self::TARGET)throw new \InvalidArgumentException('Invalid source migration version');
        return $from===self::TARGET?[]:range($from+1,self::TARGET);
    }
    /** Existing columns must not silently be treated as correct by CREATE IF NOT EXISTS. */
    public static function expectedColumns(string $sql): array {return array_keys(self::expectedTypes($sql));}
    public static function expectedTypes(string $sql): array {
        if (!preg_match('/\ACREATE TABLE IF NOT EXISTS [a-z0-9_]+\s*\(/i',$sql)) throw new \InvalidArgumentException('DDL expected');
        // Only match top-level column starts, not indexes, key names or values in quotes.
        $out=[]; $start=strpos($sql,'(')+1; $depth=1; $quote=null; $part='';
        for($i=$start,$len=strlen($sql);$i<$len;$i++){
            $c=$sql[$i];
            if($quote!==null){$part.=$c;if($c===$quote){if($i+1<$len&&$sql[$i+1]===$quote){$part.=$sql[++$i];}else{$quote=null;}}continue;}
            if($c==="'"||$c==='`'||$c==='"'){$quote=$c;$part.=$c;continue;}
            if($c==='('){$depth++;$part.=$c;continue;}
            if($c===')'){$depth--;if($depth===0){self::appendColumn($out,$part);break;}$part.=$c;continue;}
            if($c===','&&$depth===1){self::appendColumn($out,$part);$part='';continue;}
            $part.=$c;
        }
        if($depth!==0||!$out)throw new \LogicException('Incomplete DDL');
        return $out;
    }
    private static function appendColumn(array &$cols,string $part): void {
        $part=trim($part);
        if(preg_match('/\A([a-z][a-z0-9_]*)\s+(BIGINT|TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|CHAR|VARCHAR|TEXT|LONGTEXT|MEDIUMTEXT|DATE|DATETIME|TIME|TIMESTAMP|DECIMAL|BLOB|JSON)\b/i',$part,$m))$cols[$m[1]]=strtolower($m[2]==='INTEGER'?'INT':$m[2]);
    }
    /** Validates installation metadata, history and every column of all legacy tables. */
    public static function preflight(PDO $db): array {
        self::verifyDeclarations();
        $current = $db->query('SELECT install_id,schema_version,release_version,status FROM installation_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if(!$current||$current['status']!=='installed')throw new \RuntimeException('Installation record is absent or not installed');
        $old=(int)$current['schema_version'];
        if(!self::sourceSupported($old,(string)$current['release_version']))throw new \RuntimeException('Unsupported source release/schema or non-forward update');
        $stmt=$db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');$stmt->execute([$old]);
        if((int)$stmt->fetchColumn()!==1)throw new \RuntimeException('Missing prior migration stamp');
        $ahead=$db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version>?');$ahead->execute([$old]);
        if((int)$ahead->fetchColumn()!==0)throw new \RuntimeException('Unexpected future/partial migration history');
        $rows=$db->query('SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $actual=[];foreach($rows as $r){$actual[$r['table_name']][$r['column_name']]=strtolower((string)$r['data_type']);}
        $defs=self::definitions();
        foreach(self::historicTables($old) as $table){
            if(!isset($actual[$table]))throw new \RuntimeException('Missing legacy table: '.$table);
            foreach(self::expectedTypes($defs[$table]) as $column=>$type){
                if(!isset($actual[$table][$column]))throw new \RuntimeException('Legacy table column missing: '.$table.'.'.$column);
                if($actual[$table][$column]!==$type)throw new \RuntimeException('Legacy table type mismatch: '.$table.'.'.$column);
            }
        }
        $adds=self::additions($old);
        foreach($adds as $table)if(isset($actual[$table]))throw new \RuntimeException('Unexpected future table/partial upgrade: '.$table);
        return ['from'=>$old,'to'=>self::TARGET,'install_id'=>$current['install_id'],'tables_to_add'=>$adds,'release_from'=>$current['release_version']];
    }
    /** Requires the caller to have already created and verified a full backup and maintenance lock. */
    public static function apply(PDO $db): array {
        $stmt=$db->query("SELECT GET_LOCK(CONCAT('kmm_',LEFT(SHA2(DATABASE(),256),40)),0)");
        if((int)$stmt->fetchColumn()!==1)throw new \RuntimeException('Another DB updater is active');
        try{
            $plan=self::preflight($db);
            $defs=self::definitions();
            // MySQL DDL commits implicitly, even if a later statement fails. Do not fake rollback.
            foreach($plan['tables_to_add'] as $table){
                $db->exec($defs[$table]);
                $count=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
                $count->execute([$table]);
                if((int)$count->fetchColumn()!==1)throw new \RuntimeException('New table not created: '.$table);
            }
            $db->beginTransaction();
            try{
                $update=$db->prepare('UPDATE installation_state SET schema_version=?,release_version=? WHERE id=1 AND schema_version=? AND release_version=? AND status=?');
                $currentRelease=self::targetRelease();
                $update->execute([self::TARGET,$currentRelease,$plan['from'],$plan['release_from'],'installed']);
                if($update->rowCount()!==1)throw new \RuntimeException('Concurrent upgrade detected');
                $stamp=$db->prepare('INSERT INTO schema_migrations(version) VALUES (?)');
                foreach(self::migrationVersions($plan['from']) as $version)$stamp->execute([$version]);
                $event=$plan['from']===self::TARGET?'installation.release_update':'installation.schema_upgrade';
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (NULL,?,?,?,?)')
                    ->execute([$event,'installation','1',json_encode(['from_schema'=>$plan['from'],'to_schema'=>self::TARGET,'from_release'=>$plan['release_from'],'to_release'=>$currentRelease,'additions'=>count($plan['tables_to_add'])],JSON_THROW_ON_ERROR)]);
                $db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            return $plan;
        }finally{try{$db->query("SELECT RELEASE_LOCK(CONCAT('kmm_',LEFT(SHA2(DATABASE(),256),40)))")->fetchColumn();}catch(\Throwable){}}
    }
}
