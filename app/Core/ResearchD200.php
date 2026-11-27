<?php
declare(strict_types=1);
namespace KMM\Core;
/** d2.0.0 current site projection, distinct from the original historical evidence. */
final class ResearchD200 {
    public const ORIGINAL_SHA='0c175c0c3784c743e4e50dfa67e03953c5c52a70aafe224af4fc3c11655e1467';
    public const RUNTIME_SHA='c708cfb64aef74d68ac23afe9701a1b23a07d9025c5b77324bd6568701abaf1b';
    private const PIN='3c6113e3e272f382b9e3105cbc285bd5f2b80af80ab579ba89e380e7bf7da68a';
    private static ?array $pin=null,$projection=null;
    public static function pin():array {
        if(self::$pin!==null)return self::$pin;
        $raw=file_get_contents(ResearchR07::root().'/pin.json');
        if(!is_string($raw)||!hash_equals(self::PIN,hash('sha256',$raw)))throw new \RuntimeException('d2.0.0 pin mismatch');
        $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(($p['source_sha256']??null)!==self::ORIGINAL_SHA||($p['runtime_sha256']??null)!==self::RUNTIME_SHA||($p['source_status']??null)!=='released_with_documented_limitations'||($p['source_release']??null)!=='d2.0.0'||($p['production_approved']??null)!==false)throw new \RuntimeException('Invalid d2.0.0 metadata');
        return self::$pin=$p;
    }
    public static function projection():array {
        $p=self::pin();if(self::$projection!==null)return self::$projection;
        $raw=file_get_contents(ResearchR07::root().'/projection.json');
        if(!is_string($raw)||!hash_equals($p['projection_sha256'],hash('sha256',$raw)))throw new \RuntimeException('d2.0.0 projection integrity mismatch');
        $v=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        if($v['source_sha256']!==self::ORIGINAL_SHA||$v['runtime_sha256']!==self::RUNTIME_SHA||count($v['companies'])!==145||count($v['jobs'])!==180||count($v['comparison'])!==79)throw new \RuntimeException('d2.0.0 projection invalid');
        foreach(array_merge($v['jobs'],$v['comparison']) as $r)foreach(['active_slots_verified','personal_visa_verified','initial_assignment_guaranteed','strict_40min_verified','current_receipt_verified'] as $key)if(($r[$key]??null)!==false)throw new \RuntimeException('Unconfirmed condition changed');
        return self::$projection=$v;
    }
}
