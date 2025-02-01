<?php

if (!function_exists('array_is_list')) {
    function array_is_list(array $arr)
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

class JSURL {
    private static $s;
    private static $i;
    private static $len;
    private static $reserved = [
        "true" => true,
        "false" => false,
        "null" => null
    ];

    public static function stringify($v) {
        $encode = function ($s) {
            if(preg_match('/[^\w\-\.]/', $s)){
                return preg_replace_callback('/[^\w\-\.]/', function ($matches) {
                    $ch = $matches[0];
                    if ($ch === '$') return '!';
                    $ch = mb_ord($ch, 'UTF-8');
                    return $ch < 0x100 ? '*' . str_pad(dechex($ch), 2, '0', STR_PAD_LEFT) : '**' . str_pad(dechex($ch), 4, '0', STR_PAD_LEFT);
                }, $s);
            }else{
                return $s;
            }
        };

        if (is_numeric($v) && !is_string($v)) { // WSG/mrg patch to match js, may cause a few extra apos
            return is_finite($v) ? "~" . $v : "~null";
        } elseif (is_bool($v)) {
            return "~" . ($v ? "true" : "false");
        } elseif (is_string($v)) {
            return "~'" . $encode($v);
        } elseif (is_null($v)) {
            return "~null";
        } elseif (is_array($v) && array_is_list($v)) {
            // php is not strongly typed, and assoc arrays will pass is_array
            $tmpAry = array_map([self::class, 'stringify'], $v);
            return "~(" . (implode("", $tmpAry) ?: "~") . ")";
        } elseif (is_object($v) || is_array($v)) {
            $tmpAry = [];
            foreach ($v as $key => $val) {
                $val = self::stringify($val);
                if ($val !== null) {
                    $tmpAry[] = $encode($key) . $val;
                }
            }
            return "~(" . implode("~", $tmpAry) . ")";
        }
        return null;
    }

    public static function parse($s) {
        if( !$s ) return $s;
        self::$s = preg_replace('/%(25)*27/', "'", $s);
        self::$i = 0;
        self::$len = strlen(self::$s);
        return self::parseOne();
    }

    private static function eat($expected) {
        if (self::$s[self::$i] !== $expected) {
            throw new Exception("Bad JSURL syntax: expected '$expected' at position " . self::$i . ", got " . (self::$s[self::$i] ?? 'EOF'));
        }
        self::$i++;
    }

    private static function decode() {
        $beg = self::$i;
        $r = "";
        $ch = false;
        while (self::$i < self::$len && ($ch=self::$s[self::$i]) !== '~' && $ch !== ')') {
            if ($ch === '*') {
                if ($beg < self::$i) $r .= substr(self::$s, $beg, self::$i - $beg);
                if (self::$s[self::$i+1] === '*') {
                    $r .= chr(hexdec(substr(self::$s, self::$i + 2, 4)));
                    self::$i += 6;
                } else {
                    $r .= chr(hexdec(substr(self::$s, self::$i+1, 2)));
                     self::$i += 3;
                }
                $beg = self::$i;
            } elseif ($ch === '!') {
                if ($beg < self::$i) $r .= substr(self::$s, $beg, self::$i - $beg);
                $r .= '$';
                $beg = ++self::$i;
            } else {
                self::$i++;
            }
        }
        return $r . substr(self::$s, $beg, self::$i - $beg);
    }

    private static function parseOne() {
        $result = false;
        $ch = false;
        $beg = false;
        self::eat('~');
        $ch = self::$s[self::$i];
        if ( $ch === "'") {
            self::$i++;
            return self::decode();
        } elseif (self::$s[self::$i] === '(') {
            self::$i++;

            if(self::$s[self::$i] === '~'){
                $result = [];
                if(self::$s[self::$i+1] ===')' ){
                    self::$i++;
                }else{
                    do{
                        $result[] = self::parseOne();
                    }while(self::$s[self::$i] === '~');
                }
            }else{
                $result = [];
                if (self::$s[self::$i] !== ')') {
                    do {
                        $key = self::decode();
                        $result[$key] = self::parseOne();
                    } while ( self::$s[self::$i] === '~' && ++self::$i);
                }
            }
            self::eat(')');
            return $result;
        } else {
            $beg = self::$i++;
            while (self::$i < self::$len && !in_array(self::$s[self::$i], [')', '~'])) {
                self::$i++;
            }
            $sub = substr(self::$s, $beg, self::$i - $beg);
            if (preg_match('/^[\d\-]/', $ch)) {
                $result = floatval($sub);
            } elseif (isset(self::$reserved[$sub])) {
                if( !isset(self::$reserved[$sub])){
                    throw new Exception('bad value keyword: ' . $sub);
                }
                $result = self::$reserved[$sub];
            }
            return $result;
        }
    }

    public static function tryParse($s, $default = null) {
        try {
            return self::parse($s);
        } catch (Exception $e) {
            return $default;
        }
    }

}

?>
