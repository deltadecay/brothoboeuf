<?php

namespace brothoboeuf;

function is_little_endian() 
{
	$testint = 0x00FF;
	$p = pack('S', $testint);
	return $testint === unpack('v', $p)[1];
}

function zigzag_encode_sint32($value)
{
	return ($value << 1) ^ ($value >> 31);
}
function zigzag_encode_sint64($value)
{
	return ($value << 1) ^ ($value >> 63);
}

function zigzag_decode($value)
{
	return ($value >> 1) ^ (-($value & 1));
}

// Variable width integers, base 128 varints, encodes 64-bit integers between one to ten bytes,
// where smaller numbers use less bytes.
function decode_varint($bytes, $offset, $field)
{
	$value = 0;
	$bi = 0;
	$cont = true;
	while($cont)
	{
		$b = ord($bytes[$offset]);
		$cont = ($b & 0x80) > 0;
		$value += ($b & 0x7f) << (7 * $bi);
		$offset++;
		$bi++;
	}

	$fieldtype = isset($field['type']) ? $field['type'] : "int64";
	//$is_enum = isset($field['enum']) && $field['enum'];
	// int32, int64, uint32, uint64, sint32, sint64 treated as int and also enums since php doesn't have enum
	$value = intval($value);
	
	if($fieldtype == "bool") 
	{
		$value = boolval($value);
	}
	elseif($fieldtype == "sint32" || $fieldtype == "sint64")
	{
		// ZigZag decode the special signed types
		$value = zigzag_decode($value);
	}
	
	return [$value, $offset];
}

function decode_i64($bytes, $offset, $field)
{
	$value = null;
	$fieldtype = isset($field['type']) ? $field['type'] : "fixed64";
	if($fieldtype == "fixed64" || $fieldtype == "sfixed64")
	{
		if(PHP_VERSION_ID >= 70100)
			$i64 = unpack("P", $bytes, $offset);
		else
			$i64 = unpack("P", substr($bytes, $offset, 8));

		$value = intval($i64[1]);
		
		if($fieldtype == "sfixed64")
		{
			$value = zigzag_decode($value);
		}
	}
	elseif($fieldtype == "double")
	{
		if(PHP_VERSION_ID >= 70100)
			$float64 = unpack("e", $bytes, $offset);
		else
		{	
			if(is_little_endian())
				$float64 = unpack("d", substr($bytes, $offset, 8));
			else
				// unpack("d") is machine dependant, strrev since data is little endian and machine big endian
				$float64 = unpack("d", strrev(substr($bytes, $offset, 8)));
		} 
			
		$value = floatval($float64[1]);
	}
	return [$value, $offset + 8];
}

function decode_i32($bytes, $offset, $field)
{
	$value = null;
	$fieldtype = isset($field['type']) ? $field['type'] : "fixed32";
	if($fieldtype == "fixed32" || $fieldtype == "sfixed32")
	{
		// note that fixed32 should not be used for negative numbers 
		if(PHP_VERSION_ID >= 70100)
			$i32 = unpack("V", $bytes, $offset);
		else
			$i32 = unpack("V", substr($bytes, $offset, 4));
		
		/*
		$bytes4 = substr($bytes, $offset, 4);
		if(!is_little_endian())
			$bytes4 = strrev($bytes4);

		$i32 = unpack("l", $bytes4);
		*/
		
		$value = intval($i32[1]);	
	
		if($fieldtype == "sfixed32")
		{
			$value = (int)zigzag_decode($value);
		}
	}
	elseif($fieldtype == "float")
	{
		if(PHP_VERSION_ID >= 70100)
			$float32 = unpack("g", $bytes, $offset);
		else
		{	
			if(is_little_endian())
				$float32 = unpack("f", substr($bytes, $offset, 4));
			else 
				// unpack("f") is machine dependant, strrev since data is little endian and machine big endian
				$float32 = unpack("f", strrev(substr($bytes, $offset, 4)));
		} 
		$value = floatval($float32[1]);
	}
	return [$value, $offset + 4];
}

function decode_len($bytes, $offset, $field)
{
	list($len, $offset) = decode_varint($bytes, $offset, ['type' => "int32"]);
	$value = substr($bytes, $offset, $len);
	$offset += $len;

	$fieldtype = isset($field['type']) ? $field['type'] : "bytes";
	$packed = isset($field['packed']) && $field['packed'];
	if($fieldtype == 'bytes')
	{
		// if bytes, then return as is, a binary string
		// if a byte array is preferred then:
		// $value = unpack('C*', $value);
	}
	elseif($fieldtype == 'string')
	{
		// if string, then return as is
	}
	// strings and bytes cannot be packed, only primitive types covered by VARINT, I32 and I64
	elseif($packed)
	{
		$is_enum = isset($field['enum']) && $field['enum'];
		if(\in_array($fieldtype, ["int32", "int64", "uint32", "uint64", "bool", "sint32", "sint64"]) || $is_enum)
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = \strlen($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = decode_varint($packed_values, $pi, $field);
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I32 primitives
		elseif(\in_array($fieldtype, ["fixed32", "sfixed32", "float"]))
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = \strlen($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = decode_i32($packed_values, $pi, $field);
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I64 primitives
		elseif(\in_array($fieldtype, ["fixed64", "sfixed64", "double"]))
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = \strlen($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = decode_i64($packed_values, $pi, $field);
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
	}
	// sub message types are handled in a special case, those bytes will be parsed there
	
	return [$value, $offset];
}

// 
// A tag is a varint that encodes the low 3 bits for the wire type and the upper bits for the field number:
// (field_number << 3) | wire_type
function decode_tag($bytes, $offset)
{
	//$tag = ord($bytes[$offset]);
	//$offset++;
	list($tag, $offset) = decode_varint($bytes, $offset, ['type' => 'uint64']);
	$wiretype = $tag & 0x07;
	$fieldnum = $tag >> 3;
	return [$wiretype, $fieldnum, $offset];
}

function decode_value($bytes, $offset, $wiretype, $field)
{
	$value = null;
	switch($wiretype) 
	{
	case 0: // VARINT
		list($value, $offset) = decode_varint($bytes, $offset, $field);
		break;
	case 1: // I64
		list($value, $offset) = decode_i64($bytes, $offset, $field);
		break;
	case 2: // LEN
		list($value, $offset) = decode_len($bytes, $offset, $field);
		break; 
	case 3: // SGROUP
		break;
	case 4: // EGROUP
		break; 
	case 5: // I32
		list($value, $offset) = decode_i32($bytes, $offset, $field);
		break;
	default:
		break;
	}
	return [$value, $offset];
}
