<?php

namespace brothoboeuf;

function decode_tag(string $bytes, $i)
{
	$b = ord($bytes[$i]);
	$wiretype = $b & 0x07;
	$fieldnum = $b >> 3;
	return [$wiretype, $fieldnum, $i + 1];
}


function zigzag_decode($value)
{
	return ($value >> 1) ^ (-($value & 1));
}

function decode_varint(string $bytes, $i, $field)
{
	$value = 0;
	$bi = 0;
	$cont = true;
	while($cont)
	{
		$b = ord($bytes[$i]);
		$cont = ($b & 0x80) > 0;
		$value += ($b & 0x7f) << (7 * $bi);
		$i++;
		$bi++;
	}

	$fieldtype = $field['type'];
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
	
	return [$value, $i];
}

function decode_i64(string $bytes, $i, $field)
{
	$i64bytes = substr($bytes, $i, 8);
	//$up = unpack("Jval", pack('C8', ...$i64bytes));
	$up = unpack("Jval", $i64bytes);
	if($up !== false)
		$value = $up['val'];
	$i += 8;

	$fieldtype = $field['type'];
	
	if($fieldtype == "fixed64")
	{
		$value = intval($value);
	}
	elseif($fieldtype == "sfixed64")
	{
		$value = zigzag_decode(intval($value));
	}
	elseif($fieldtype == "double")
	{
		$float64 = unpack("E", pack('J', $value));
		$value = floatval($float64[1]);
	}
	
	return [$value, $i];
}

function decode_i32(string $bytes, $i, $field)
{
	$i32bytes = substr($bytes, $i, 4);
	//$up = unpack("Nval", pack('C4', ...$i32bytes));
	$up = unpack("Nval", $i32bytes);
	if($up !== false)
		$value = $up['val'];
	$i += 4;

	$fieldtype = $field['type'];
	if($fieldtype == "fixed32")
	{
		$value = intval($value);		
	}
	elseif($fieldtype == "sfixed32")
	{
		$value = zigzag_decode(intval($value));
	}
	elseif($fieldtype == "float")
	{
		$float32 = unpack("G", pack('N', $value));
		$value = floatval($float32[1]);
	}
	
	return [$value, $i];
}

function decode_len(string $bytes, $i, $field)
{
	list($len, $i) = decode_varint($bytes, $i, ['type' => "int32"]);
	/*$value = [];
	for($si=0; $si<$len; $si++)
	{
		$value[$si] = ord($bytes[$i]);
		$i++;
	}*/
	$value = substr($bytes, $i, $len);
	$i += $len;

	$fieldtype = $field['type'];
	$packed = isset($field['packed']) && $field['packed'];
	if($fieldtype == 'bytes')
	{
		// if bytes, then return as is
		// a binary string, if a bate array is preferred
		// $value = unpack('C*', $value);
	}
	elseif($fieldtype == 'string')
	{
		//$value = array_map('chr', $value);
		//$value = implode('', $value);
		// if string, then return as is
	}
	// strings and bytes cannot be packed, only primitive types covered by VARINT, I32 and I64
	elseif($packed)
	{
		$is_enum = isset($field['enum']) && $field['enum'];
		if(in_array($fieldtype, ["int32", "int64", "uint32", "uint64", "bool", "sint32", "sint64"]) || $is_enum)
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = strlen($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = decode_varint($packed_values, $pi, $field);
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I32 primitives
		elseif(in_array($fieldtype, ["fixed32", "sfixed32", "float"]))
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = strlen($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = decode_i32($packed_values, $pi, $field);
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I64 primitives
		elseif(in_array($fieldtype, ["fixed64", "sfixed64", "double"]))
		{
			$packed_values = $value; 
			$unpacked_values = [];
			$n = strlen($packed_values);
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
	
	return [$value, $i];
}
	
function decode_value(string $bytes, $i, $wiretype, $field)
{
	switch($wiretype) 
	{
	case 0: // VARINT
		list($value, $i) = decode_varint($bytes, $i, $field);
		break;
	case 1: // I64
		list($value, $i) = decode_i64($bytes, $i, $field);
		break;
	case 2: // LEN
		list($value, $i) = decode_len($bytes, $i, $field);
		break; 
	case 3: // SGROUP
		break;
	case 4: // EGROUP
		break; 
	case 5: // I32
		list($value, $i) = decode_i32($bytes, $i, $field);
		break;
	default:
		break;
	}
	return [$value, $i];
}
