<?php
declare(strict_types=1);
namespace KMM\Core;

/** The only data that a member can request to change. Review is mandatory. */
final class ProposalData {
    public const TYPES=['companies','brands','company_locations','jobs'];
    public const ACTIONS=['create','update','archive'];
    public const DECISIONS=['approve','reject','needs_revision'];
    public static function kind(mixed $raw): string {
        if(!is_string($raw) || !in_array($raw,self::TYPES,true))throw new \InvalidArgumentException('Unsupported proposal kind');
        return $raw;
    }
    public static function action(mixed $raw): string {
        if(!is_string($raw) || !in_array($raw,self::ACTIONS,true))throw new \InvalidArgumentException('Unsupported proposal action');
        return $raw;
    }
    public static function fields(string $type,string $action,array $input): ?array {
        self::kind($type);self::action($action);
        if($action==='archive')return null;
        $fields=BusinessData::fields($type,$input);
        BusinessData::requireProvenance($type,$fields);
        // No users can force publication, assert verified location, or mark recruitment confirmed.
        if($type==='companies' && $fields['visibility']!=='draft' && $action==='create')throw new \InvalidArgumentException('New companies must begin as draft');
        if($type==='companies' && $action==='update')unset($fields['visibility']);
        if($type==='company_locations' && $fields['verification_state']==='verified')throw new \InvalidArgumentException('Only an administrator may verify a location');
        if($type==='jobs' && $fields['recruitment_state']==='confirmed_open')throw new \InvalidArgumentException('Only an administrator may confirm recruitment');
        if($type==='companies'){
            $src=$input['company_source_id']??null;
            if($src!=='' && $src!==null){
                if(!is_string($src))throw new \InvalidArgumentException('Invalid company source');
                $fields['company_source_id']=BusinessData::id($src);
            }
        }
        return $fields;
    }
    public static function reason(mixed $raw):string {
        $reason=BusinessData::text($raw,1500,true);
        if(preg_match_all('/./us',$reason)<6)throw new \InvalidArgumentException('Please describe a reason (6+ characters)');
        return $reason;
    }
    public static function evidence(mixed $raw):?string {return BusinessData::url($raw??'');}
    public static function publicSummary(string $type,string $operation): string {
        self::kind($type);self::action($operation);
        $name=['companies'=>'企業情報','brands'=>'ブランド情報','company_locations'=>'拠点情報','jobs'=>'求人情報'][$type];
        $verb=['create'=>'新規登録','update'=>'更新','archive'=>'アーカイブ'][$operation];
        return $name.'の'.$verb;
    }
    public static function visibleTarget(string $type,array $record,?array $company):bool {
        self::kind($type);
        if($type==='companies')return ($record['visibility']??'')==='published';
        if(($company['visibility']??'')!=='published'||($record['archived_at']??null)!==null)return false;
        if($type==='company_locations')return ($record['verification_state']??'')==='verified';
        return true;
    }
}
