<?php

namespace brothoboeuf;

function parse_tag($bytes, $i)
{
	$b = $bytes[$i];
	$wiretype = $b & 0x07;
	$fieldnum = $b >> 3;
	return [$wiretype, $fieldnum, $i + 1];
}


function zigzag_decode($value)
{
	return ($value >> 1) ^ (-($value & 1));
}

function parse_varint($bytes, $i, $field)
{
	$value = 0;
	$bi = 0;
	while(true)
	{
		$cont = ($bytes[$i] & 0x80) > 0;
		$value += ($bytes[$i] & 0x7f) << (7 * $bi);
		$i++;
		if(!$cont)
			break;
		$bi++;
	}

	$fieldtype = $field['type'];
	//$is_enum = isset($field['enum']) && $field['enum'];
	// int32, int64, uint32, uint64, sint32, sint64 treated as is and also enums since php doesn't have enum
	$value = intval($value);
	
	if($fieldtype == "bool") 
	{
		$value = boolval($value);
	}
	elseif(in_array($fieldtype, ["sint32", "sint64"]))
	{
		// ZigZag decode the special signed types
		$value = zigzag_decode($value);
	}
	
	return [$value, $i];
}

function parse_i64($bytes, $i, $field)
{
	$i64bytes = array_slice($bytes, $i, 8);
	$up = unpack("Jval", pack('C8', ...$i64bytes));
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
		// ZigZag decode
		$value = zigzag_decode(intval($value));
	}
	elseif($fieldtype == "double")
	{
		$float64 = unpack("E", pack('J', $value));
		$value = floatval($float64[1]);
	}
	
	return [$value, $i];
}

function parse_i32($bytes, $i, $field)
{
	$i32bytes = array_slice($bytes, $i, 4);
	$up = unpack("Nval", pack('C4', ...$i32bytes));
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
		// ZigZag decode
		$value = zigzag_decode(intval($value));
	}
	elseif($fieldtype == "float")
	{
		$float32 = unpack("G", pack('N', $value));
		$value = floatval($float32[1]);
	}
	
	return [$value, $i];
}

function parse_len($bytes, $i, $field)
{
	list($len, $i) = parse_varint($bytes, $i, ['type' => "int32"]);
	$value = [];
	for($si=0; $si<$len; $si++)
	{
		$value[$si] = $bytes[$i];
		$i++;
	}

	$fieldtype = $field['type'];
	$packed = isset($field['packed']) && $field['packed'];
	if($fieldtype == 'bytes')
	{
		// if bytes, then return as is
	}
	elseif($fieldtype == 'string')
	{
		$value = array_map('chr', $value);
		$value = implode('', $value);
	}
	// strings and bytes cannot be packed, only primitive types covered by VARINT, I32 and I64
	elseif($packed)
	{
		$is_enum = isset($field['enum']) && $field['enum'];
		if(in_array($fieldtype, ["int32", "int64", "uint32", "uint64", "bool", "sint32", "sint64"]) || $is_enum)
		{
			//$is_bool = $fieldtype == "bool";
			//$is_zigzag = in_array($fieldtype, ["sint32", "sint64"]);
			$packed_values = $value; 
			$unpacked_values = [];
			$n = count($packed_values);
			$pi = 0;
			while($pi < $n)
			{
				list($pval, $pi) = parse_varint($packed_values, $pi, $field);
				/*$pval = intval($pval);
				if($is_zigzag)
				{
					$pval = zigzag_decode($pval);
				}
				if($is_bool) 
				{
					$pval = boolval($pval);
				}*/
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I32 primitives
		elseif(in_array($fieldtype, ["fixed32", "sfixed32", "float"]))
		{
			//$is_zigzag = $fieldtype == "sfixed32";
			//$is_float = $fieldtype == "float";
			$packed_values = $value; 
			$unpacked_values = [];
			$n = count($packed_values);
			$pi = 0;
			//for($pi=0; $pi<$n; $pi+=4)
			while($pi < $n)
			{
				list($pval, $pi) = parse_i32($packed_values, $pi, $field);
				/*$pdata = pack('C4', $packed_values[$pi], $packed_values[$pi+1], $packed_values[$pi+2], $packed_values[$pi+3]);
				if($is_float)
				{
					$float32 = unpack("G", $pdata);
					$pval = floatval($float32[1]);
				}
				else
				{
					$int32 = unpack("N", $pdata);
					$pval = intval($int32[1]);
					if($is_zigzag)
					{
						$pval = zigzag_decode($pval);
					}
				}*/
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
		// The I64 primitives
		elseif(in_array($fieldtype, ["fixed64", "sfixed64", "double"]))
		{
			//$is_zigzag = $fieldtype == "sfixed64";
			//$is_float = $fieldtype == "double";
			$packed_values = $value; 
			$unpacked_values = [];
			$n = count($packed_values);
			$pi = 0;
			//for($pi=0; $pi<$n; $pi+=8)
			while($pi < $n)
			{
				list($pval, $pi) = parse_i64($packed_values, $pi, $field);
				/* 
				$pdata = pack('C8', $packed_values[$pi], $packed_values[$pi+1], $packed_values[$pi+2], $packed_values[$pi+3],
									$packed_values[$pi+4], $packed_values[$pi+5], $packed_values[$pi+6], $packed_values[$pi+7]);
				if($is_float)
				{
					$float64 = unpack("E", $pdata);
					$pval = floatval($float64[1]);
				}
				else
				{
					$int64 = unpack("J", $pdata);
					$pval = intval($int64[1]);
					if($is_zigzag)
					{
						$pval = zigzag_decode($pval);
					}
				}*/
				$unpacked_values []= $pval;
			}
			$value = $unpacked_values;
		}
	}
	// sub message types are handled in a special case, those bytes will be parsed there
	
	return [$value, $i];
}
	
function parse_value($bytes, $i, $wiretype, $field)
{
	switch($wiretype) 
	{
	case 0: // VARINT
		list($value, $i) = parse_varint($bytes, $i, $field);
		break;
	case 1: // I64
		list($value, $i) = parse_i64($bytes, $i, $field);
		break;
	case 2: // LEN
		list($value, $i) = parse_len($bytes, $i, $field);
		break; 
	case 3: // SGROUP
		break;
	case 4: // EGROUP
		break; 
	case 5: // I32
		list($value, $i) = parse_i32($bytes, $i, $field);
		break;
	default:
		break;
	}
	return [$value, $i];
}
