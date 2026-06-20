<?php
if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

class AutoreplyProtobuf
{
    /**
     * Encode a varint (variable-length integer)
     */
    public static function encodeVarint($value)
    {
        $result = '';
        $value = (int)$value;
        while ($value >= 0x80) {
            $result .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $result .= chr($value & 0x7F);
        return $result;
    }

    /**
     * Encode a field tag: (field_number << 3) | wire_type
     */
    public static function encodeTag($field_number, $wire_type)
    {
        return self::encodeVarint(($field_number << 3) | $wire_type);
    }

    /**
     * Encode a string field (wire_type=2): tag + length_varint + bytes
     */
    public static function encodeString($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 2);
        $value = (string)$value;
        $len = self::encodeVarint(strlen($value));
        return $tag . $len . $value;
    }

    /**
     * Encode an int32 field (wire_type=0): tag + varint_value
     */
    public static function encodeInt32($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 0);
        return $tag . self::encodeVarint((int)$value);
    }

    /**
     * Encode an int64 field (wire_type=0): tag + varint_value
     */
    public static function encodeInt64($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 0);
        return $tag . self::encodeVarint((int)$value);
    }

    /**
     * Encode a float field (wire_type=5): tag + 4 bytes little-endian IEEE 754
     */
    public static function encodeFloat($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 5);
        return $tag . pack('f', (float)$value);
    }

    /**
     * Encode a double field (wire_type=1): tag + 8 bytes little-endian IEEE 754
     */
    public static function encodeDouble($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 1);
        return $tag . pack('d', (float)$value);
    }

    /**
     * Encode a bool field (wire_type=0): tag + varint_value (0 or 1)
     */
    public static function encodeBool($field_number, $value)
    {
        $tag = self::encodeTag($field_number, 0);
        return $tag . self::encodeVarint($value ? 1 : 0);
    }

    /**
     * Encode a sub-message field (wire_type=2): tag + length_varint + encoded_bytes
     */
    public static function encodeMessage($field_number, $data)
    {
        $tag = self::encodeTag($field_number, 2);
        $len = self::encodeVarint(strlen($data));
        return $tag . $len . $data;
    }
}
