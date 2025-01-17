<?php
// a simple parser for Valve's KeyValue format
// https://developer.valvesoftware.com/wiki/KeyValues
//
// author: Rossen Popov, 2015-2016

function vdf_decode($text, $load_base_files = false) {
    if(!is_string($text)) {
        trigger_error("vdf_decode expects parameter 1 to be a string, " . gettype($text) . " given.", E_USER_NOTICE);
        return NULL;
    }

    // detect and convert utf-16, utf-32 and convert to utf8
    if      (substr($text, 0, 2) == "\xFE\xFF")         $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16BE');
    else if (substr($text, 0, 2) == "\xFF\xFE")         $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16LE');
    else if (substr($text, 0, 4) == "\x00\x00\xFE\xFF") $text = mb_convert_encoding($text, 'UTF-8', 'UTF-32BE');
    else if (substr($text, 0, 4) == "\xFF\xFE\x00\x00") $text = mb_convert_encoding($text, 'UTF-8', 'UTF-32LE');

    // strip BOM
    $text = preg_replace('/^[\xef\xbb\xbf\xff\xfe\xfe\xff]*/', '', $text);

    $lines = preg_split('/\n/', $text);

    $base_arr = array();
    $arr = array();
    $stack = array(0=>&$arr);
    $expect_bracket = false;
    $is_parsing_root = true;
    $name = "";

    $re_keyvalue = '~^("(?P<qkey>(?:\\\\.|[^\\\\"])+)"|(?P<key>[a-z0-9\\-\\_]+))' .
                   '([ \t]*(' .
                   '"(?P<qval>(?:\\\\.|[^\\\\"])*)(?P<vq_end>")?' .
                   '|(?P<val>[a-z0-9\\-\\_]+)' .
                   '))?~iu';

    $j = count($lines);
    for($i = 0; $i < $j; $i++) {
        $line = trim($lines[$i]);
        $lineParts = NULL;

        // skip empty and comment lines
        if ($line == "" || $line[0] == '/') { continue; }

        if (
            $is_parsing_root && count($lineParts = preg_split('/\s+/', $line, 2, PREG_SPLIT_NO_EMPTY)) > 1
            && strcasecmp($lineParts[0], "#base") == 0
        ) {
            if ($load_base_files) {
                $base_path = trim($lineParts[1], '"');
                $aux_arr = vdf_decode(file_get_contents($base_path), false);

                if (!empty($aux_arr)) {
                    merge_unique_values(reset($aux_arr), $base_arr);
                }
            }

            continue;
        }

        // one level deeper
        if( $line[0] == "{" ) {
            $expect_bracket = $is_parsing_root = false;
            continue;
        }

        if($expect_bracket) {
            trigger_error("vdf_decode: invalid syntax, expected a '}' on line " . ($i+1), E_USER_NOTICE);
            return NULL;
        }

        // one level back
        if( $line[0] == "}" ) {
            array_pop($stack);
            continue;
        }

        // nessesary for multiline values
        while(True) {
            preg_match($re_keyvalue, $line, $m);

            if(!$m) {
                trigger_error("vdf_decode: invalid syntax on line " . ($i+1), E_USER_NOTICE);
                return NULL;
            }

            $key = (isset($m['key']) && $m['key'] !== "")
                     ? $m['key']
                     : $m['qkey'];
            $val = (isset($m['qval']) && (!isset($m['vq_end']) || $m['vq_end'] !== ""))
                     ? $m['qval']
                     : (isset($m['val']) ? $m['val'] : False);

            if($val === False) {
                // chain (merge*) duplicate key
                if(!isset($stack[count($stack)-1][$key])) {
                    $stack[count($stack)-1][$key] = array();
                }
                $stack[count($stack)] = &$stack[count($stack)-1][$key];
                $expect_bracket = true;
            }
            else {
                // if don't match a closing quote for value, we consome one more line, until we find it
                if(!isset($m['vq_end']) && isset($m['qval'])) {
                    $line .= "\n" . $lines[++$i];
                    continue;
                }

                $stack[count($stack)-1][$key] = $val;
            }
            break;
        }
    }

    if (count($stack) !== 1) {
        trigger_error("vdf_decode: open parentheses somewhere", E_USER_NOTICE);
        return NULL;
    } else if (!empty($base_arr)) {
        $groupsArr = &$arr[array_keys($arr)[0]];
        $groupsArr = array_merge_recursive($base_arr, $groupsArr);
    }

    return $arr;
}

function merge_unique_values($src, &$dest) {
    foreach ($src as $key => $value) {
        if (empty($dest[$key])) {
            $dest[$key] = $value;
        } else if (is_array($value)) {
            merge_unique_values($value, $dest[$key]);
        }
    }
}

function vdf_encode($arr, $pretty = false) {
    if(!is_array($arr)) {
        trigger_error("vdf_encode expects parameter 1 to be an array, " . gettype($arr) . " given.", E_USER_NOTICE);
        return NULL;
    }

    $pretty = (boolean) $pretty;

    return vdf_encode_step($arr, $pretty, 0);
}

function vdf_encode_step($arr, $pretty, $level) {
    if(!is_array($arr)) {
        trigger_error("vdf_encode encounted " . gettype($arr) . ", only array or string allowed (depth ".$level.")", E_USER_NOTICE);
        return NULL;
    }

    $buf = "";
    $line_indent = ($pretty) ? str_repeat("\t", $level) : "";

    foreach($arr as $k => $v) {
        if(is_string($v)) {
            $buf .= "$line_indent\"$k\" \"$v\"\n";
        }
        else {
            $res = vdf_encode_step($v, $pretty, $level + 1);
            if($res === NULL) return NULL;

            $buf .= "$line_indent\"$k\"\n$line_indent{\n$res$line_indent}\n";
        }
    }

    return $buf;
}
