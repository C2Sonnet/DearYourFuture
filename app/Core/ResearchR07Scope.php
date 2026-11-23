<?php
declare(strict_types=1);
namespace KMM\Core;
/** The removed research-source IDs remain auditable, but are not current directory entries. */
final class ResearchR07Scope {
    private const FILE_HASHES = ['OUT_OF_SCOPE_ARCHIVE_d1.0.4_R07_BATCH32_20261123.json' => '924c44b25f46cd05e8bb5821032ba38c0589bc8599cb9e692ca93f07dcc8a5ca', 'OUT_OF_SCOPE_EMPLOYMENT_ARCHIVE_d1.0.4_R07_BATCH33_20261123.json' => '1cecdbccf8e5b71df62ff88526ae89d28f56a9fa7c1ba7a7ba1a42257b3d3991'];
    private static ?array $ids = null;
    public static function ids():array {
        if(self::$ids!==null)return self::$ids;
        $out=['companies'=>[],'jobs'=>[]];
        foreach(self::FILE_HASHES as $filename=>$hash){
            $path=ResearchR07::root().'/'.$filename;
            if(!is_file($path)||!hash_equals($hash,(string)hash_file('sha256',$path)))
                throw new \RuntimeException('R07 excluded archive integrity mismatch');
            $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
            foreach($data['job_records']??[] as $job){if(is_array($job)&&ResearchR07::isJobId((string)($job['job_id']??'')))$out['jobs'][$job['job_id']]=true;}
            foreach($data['company_records_wholly_archived']??[] as $co){if(is_array($co)&&ResearchR07::isCompanyId((string)($co['company_id']??'')))$out['companies'][$co['company_id']]=true;}
        }
        foreach($out['jobs'] as $id=>$_)if(ResearchR07::job($id)!==null)throw new \RuntimeException('Archived job overlaps current research');
        foreach($out['companies'] as $id=>$_)if(ResearchR07::company($id)!==null)throw new \RuntimeException('Archived company overlaps current research');
        return self::$ids=$out;
    }
    public static function excluded(string $kind,string $sourceId):bool{return isset(self::ids()[$kind][$sourceId]);}
}
