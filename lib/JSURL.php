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
        // Mirrors the JS implementation: ASCII word chars, "-" and "." pass through;
        // "$" becomes "!"; everything else is escaped per UTF-16 code unit
        // (*xx for < 0x100, **xxxx otherwise). The /u flag makes the regex match
        // whole UTF-8 characters instead of individual bytes.
        $encode = function ($s) {
            $s = (string)$s;
            if (!preg_match('/[^A-Za-z0-9_\-\.]/u', $s)) {
                return $s;
            }
            return preg_replace_callback('/[^A-Za-z0-9_\-\.]/u', function ($matches) {
                $ch = $matches[0];
                if ($ch === '$') return '!';
                $cp = mb_ord($ch, 'UTF-8');
                if ($cp < 0x100) {
                    return '*' . str_pad(dechex($cp), 2, '0', STR_PAD_LEFT);
                }
                if ($cp > 0xFFFF) {
                    // JS strings are UTF-16, so astral characters become a surrogate pair
                    $cp -= 0x10000;
                    return '**' . dechex(0xD800 + ($cp >> 10)) . '**' . dechex(0xDC00 + ($cp & 0x3FF));
                }
                return '**' . str_pad(dechex($cp), 4, '0', STR_PAD_LEFT);
            }, $s);
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
                if ((self::$s[self::$i+1] ?? '') === '*') {
                    $cp = hexdec(substr(self::$s, self::$i + 2, 4));
                    self::$i += 6;
                    // Recombine a UTF-16 surrogate pair (**d83d**de00) into one code point
                    if ($cp >= 0xD800 && $cp <= 0xDBFF
                        && preg_match('/^\*\*([dD][c-fC-F][0-9a-fA-F]{2})/', substr(self::$s, self::$i, 6), $m)) {
                        $cp = 0x10000 + (($cp - 0xD800) << 10) + (hexdec($m[1]) - 0xDC00);
                        self::$i += 6;
                    }
                    // lone surrogates can't be represented in UTF-8
                    $r .= ($cp >= 0xD800 && $cp <= 0xDFFF) ? "\u{FFFD}" : mb_chr($cp, 'UTF-8');
                } else {
                    $r .= mb_chr(hexdec(substr(self::$s, self::$i+1, 2)), 'UTF-8');
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
