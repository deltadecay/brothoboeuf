<?php

namespace brothoboeuf;

require_once(__DIR__."/util.php");


class ProtoBufMessage
{
	private $name = '';
	private $fields = [];
	private $parsed = [];
	private $enums = [];
	private $messages = [];
	//private $oneofs = [];
	//private $extensions = [];
	//private $reserved = [];

	public function __construct($name = '')
	{
		if($name == '')
		{
			throw new \Exception('Message name cannot be empty');
		}
		$this->name = $name;
	}

	public function getName() { return $this->name; }

	public function register_enum($name = '', $valuemap = [])
	{
		if($name == '')
		{
			throw new \Exception('Enum name cannot be empty');
		}
		$this->enums[$name] = $valuemap;
	}

	public function register_message(ProtoBufMessage $message)
	{
		$name = $message->getName();
		$this->messages[$name] = $message;
	}
	
	public function define_field($name, $type, $fieldnum, $default = '', $optional = true)
	{
		if($type == "bool")
		{
			$default = ($default == 1 || $default == true) ? true : false;
		}
		if($type == "int32" || $type == "int64" || $type == "uint32" || $type == "uint64" || $type == "sint32" || $type == "sint64" || 
			$type == "fixed32" || $type == "fixed64" || $type == "sfixed32" || $type == "sfixed64")
		{
			$default = intval($default);
		}
		elseif($type == "float" || $type == "double")
		{
			$default = floatval($default);
		}
		elseif($type == "string")
		{
			$default = strval($default);
		}
		elseif($type == "bytes")
		{
			$default = null;
		}
		elseif($this->messages[$type])
		{
			// Sub messages default to null
			$default = null;
		}
		elseif($this->enums[$type])
		{
			// Enums are ints
			$default = intval($default);
		}

		$this->fields[$fieldnum] = ['repeated' => false, 'name' => $name, 'type' => $type, 'default' => $default, 'optional' => $optional];
		$this->parsed[$fieldnum] = ['value' => $default];
	}
	public function define_repeated_field($name, $type, $fieldnum, $packed = true)
	{
		$this->fields[$fieldnum] = ['repeated' => true, 'name' => $name, 'type' => $type, 'packed' => $packed];
		$this->parsed[$fieldnum] = ['value' => []];
	}

	private function post_process_value($value, $fieldtype)
	{
		// Special cases for enum and sub message types
		if(isset($this->enums[$fieldtype]))
		{
			// php doesn't have enums, so just return the integer
			$value = intval($value);
		}
		elseif(isset($this->messages[$fieldtype]))
		{
			// The value is a sequence of bytes and must now be parsed to correct sub message.
			$submsg = clone ($this->messages[$fieldtype]);
			$submsg_bytes = $value;
			$value = $submsg->decode($submsg_bytes);
		}

		return $value;
	}
	
	public function decode($bytes)
	{
		if(is_array($bytes))
		{
			$bytes = pack("C*", ...$bytes);
		}
		if(!is_string($bytes))
		{
			throw new \Exception("parameter bytes must be a binary string or an array of bytes");
		}
		// Parse key-value pairs
		$i = 0;
		$n = \strlen($bytes);
		while ($i < $n)
		{
			list($wiretype, $fieldnum, $i) = decode_tag($bytes, $i);
			$field = $this->fields[$fieldnum];
			$fieldtype = $field['type'];

			// Tag extra type info for enums and submessages before parsing value
			if(isset($this->enums[$fieldtype]))
			{
				$field['enum'] = true;
			}
			if(isset($this->messages[$fieldtype]))
			{
				$field['submessage'] = true;
			}

			list($value, $i) = decode_value($bytes, $i, $wiretype, $field);

			if($field['repeated'])
			{
				// For repeated, the values make an array so we append
				if(is_array($value))
				{
					foreach($value as $val)
					{
						$this->parsed[$fieldnum]['value'][] = $this->post_process_value($val, $fieldtype);
					}
				}
				else
				{
					$this->parsed[$fieldnum]['value'][] = $this->post_process_value($value, $fieldtype);
				}
			} 
			else
			{
				$this->parsed[$fieldnum]['value'] = $this->post_process_value($value, $fieldtype);
			}
			$this->parsed[$fieldnum]['wiretype'] = $wiretype;
			
		}

		// Build the assoc array object from the parsed values
		$obj = ["@type" => $this->getName()];
		foreach($this->parsed as $fieldnum => $parsedval)
		{
			$field = $this->fields[$fieldnum];
			$value = $parsedval['value'];
			$obj[$field['name']] = $value;
		}

		return $obj;
	}
}

