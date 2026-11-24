<?php
declare(strict_types=1);
namespace KMM\Core;

/**
 * Signup avatar created locally from the chosen display name or public handle.
 * A deterministic pixel-font PNG avoids GD and server font dependencies during
 * account creation. The file is private and is replaced/deleted after an upload.
 */
final class InitialAvatar {
    private const EDGE = 512;
    private const PALETTE = [
        [220, 236, 231], [231, 222, 240], [243, 225, 214],
        [221, 231, 246], [247, 232, 219], [224, 237, 237],
    ];
    /** 5×7 original bitmap patterns. The public handle is ASCII by design. */
    private const FONT = [
        'A'=>['01110','10001','10001','11111','10001','10001','10001'],
        'B'=>['11110','10001','10001','11110','10001','10001','11110'],
        'C'=>['01111','10000','10000','10000','10000','10000','01111'],
        'D'=>['11110','10001','10001','10001','10001','10001','11110'],
        'E'=>['11111','10000','10000','11110','10000','10000','11111'],
        'F'=>['11111','10000','10000','11110','10000','10000','10000'],
        'G'=>['01111','10000','10000','10111','10001','10001','01111'],
        'H'=>['10001','10001','10001','11111','10001','10001','10001'],
        'I'=>['11111','00100','00100','00100','00100','00100','11111'],
        'J'=>['00111','00010','00010','00010','10010','10010','01100'],
        'K'=>['10001','10010','10100','11000','10100','10010','10001'],
        'L'=>['10000','10000','10000','10000','10000','10000','11111'],
        'M'=>['10001','11011','10101','10101','10001','10001','10001'],
        'N'=>['10001','11001','10101','10011','10001','10001','10001'],
        'O'=>['01110','10001','10001','10001','10001','10001','01110'],
        'P'=>['11110','10001','10001','11110','10000','10000','10000'],
        'Q'=>['01110','10001','10001','10001','10101','10010','01101'],
        'R'=>['11110','10001','10001','11110','10100','10010','10001'],
        'S'=>['01111','10000','10000','01110','00001','00001','11110'],
        'T'=>['11111','00100','00100','00100','00100','00100','00100'],
        'U'=>['10001','10001','10001','10001','10001','10001','01110'],
        'V'=>['10001','10001','10001','10001','10001','01010','00100'],
        'W'=>['10001','10001','10001','10101','10101','10101','01010'],
        'X'=>['10001','10001','01010','00100','01010','10001','10001'],
        'Y'=>['10001','10001','01010','00100','00100','00100','00100'],
        'Z'=>['11111','00001','00010','00100','01000','10000','11111'],
        '0'=>['01110','10011','10101','10101','10101','11001','01110'],
        '1'=>['00100','01100','00100','00100','00100','00100','01110'],
        '2'=>['01110','10001','00001','00010','00100','01000','11111'],
        '3'=>['11110','00001','00001','01110','00001','00001','11110'],
        '4'=>['00010','00110','01010','10010','11111','00010','00010'],
        '5'=>['11111','10000','10000','11110','00001','00001','11110'],
        '6'=>['01111','10000','10000','11110','10001','10001','01110'],
        '7'=>['11111','00001','00010','00100','01000','01000','01000'],
        '8'=>['01110','10001','10001','01110','10001','10001','01110'],
        '9'=>['01110','10001','10001','01111','00001','00001','11110'],
    ];

    /** Latin display names use initials; CJK/non-Latin names use the account handle. */
    public static function initials(string $handle,string $displayName): string {
        $displayName = trim($displayName);
        if ($displayName !== '' && preg_match('/\A[A-Za-z0-9 _.-]+\z/D', $displayName) === 1) {
            $words = preg_split('/[\s._-]+/', $displayName, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($words) && $words !== []) {
                if (count($words) >= 2) $text = substr($words[0],0,1).substr($words[1],0,1);
                else $text = substr($words[0],0,2);
                if (preg_match('/\A[A-Za-z0-9]{1,2}\z/D', $text) === 1) return strtoupper($text);
            }
        }
        $text = strtoupper(substr($handle, 0, 2));
        if (preg_match('/\A[A-Z0-9]{1,2}\z/D', $text) !== 1) throw new \InvalidArgumentException('Invalid public handle for avatar');
        return $text;
    }

    /** Create a private 512px PNG; independent of GD/TTF and external services. */
    public static function create(string $handle,string $displayName,?string $directory=null): string {
        $initials = self::initials($handle,$displayName);
        $bytes = self::png($initials, $handle);
        return AvatarFileStore::save($directory ?? self::directory(),'png',static function(string $stage)use($bytes):bool {
            return @file_put_contents($stage,$bytes,LOCK_EX)===strlen($bytes);
        });
    }
    public static function discard(string $filename,?string $directory=null):void {
        AvatarFileStore::discard($directory ?? self::directory(),$filename);
    }
    public static function directory():string {
        return SiteState::root().'/storage/private/avatars';
    }

    /** Pure encoder used by offline tests; no user text is emitted into HTML/SVG. */
    public static function png(string $initials,string $handle):string {
        if (preg_match('/\A[A-Z0-9]{1,2}\z/D',$initials)!==1) throw new \InvalidArgumentException('Unsupported avatar initials');
        if (!function_exists('gzcompress')) throw new \RuntimeException('Zlib required for default avatar PNG');
        $hash=crc32(strtolower($handle));
        $colors=self::PALETTE[$hash%count(self::PALETTE)];
        $background=pack('C3',...$colors);
        $foreground=pack('C3',45,72,79);
        $scale=28;
        $symbols=str_split($initials);
        $width=(5*count($symbols)+max(0,count($symbols)-1))*$scale;
        $x0=intdiv(self::EDGE-$width,2);
        $y0=intdiv(self::EDGE-7*$scale,2);
        $raw='';
        $fontRows=array_map(static fn($c)=>self::FONT[$c],$symbols);
        for($y=0;$y<self::EDGE;$y++) {
            $line=str_repeat($background,self::EDGE);
            $glyphY=intdiv($y-$y0,$scale);
            if($y >= $y0 && $glyphY >= 0 && $glyphY < 7) {
                foreach($fontRows as $index=>$glyph) {
                    for($column=0;$column<5;$column++) {
                        if($glyph[$glyphY][$column] !== '1')continue;
                        $x=$x0+(6*$index+$column)*$scale;
                        $line=substr_replace($line,str_repeat($foreground,$scale),3*$x,3*$scale);
                    }
                }
            }
            $raw.="\x00".$line;
        }
        $compressed=gzcompress($raw,9);
        if(!is_string($compressed))throw new \RuntimeException('Failed to encode PNG');
        $chunk=static function(string $type,string $payload):string {
            return pack('N',strlen($payload)).$type.$payload.pack('N',crc32($type.$payload));
        };
        $result="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',self::EDGE,self::EDGE,8,2,0,0,0))
            .$chunk('IDAT',$compressed).$chunk('IEND','');
        if(strlen($result)>AvatarImagePolicy::MAX_BYTES)throw new \RuntimeException('Generated avatar exceeds limit');
        return $result;
    }
}
