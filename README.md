# Brothoboeuf

Broth of beef? No, but a simple protobuf decoder in php. Simple as in no dependencies such as parsing of proto spec files
and generation of code with the protoc compiler. The message types are built in code and decodes into an associative array.

```php

require_once("brothoboeuf.php");
use brothoboeuf\ProtoBufMessage;

/*
	message Test1 {
		int32 a = 1;
		string b = 2;
	}
	message Test2 {
		Test1 c = 3;
	}
*/

$bytes = "\x1a\x0c\x08\x96\x01\x12\x07\x74\x65\x73\x74\x69\x6e\x67";
//$bytes = "\x1a\x0c\x08\x96\x01\x12\x07testing";
//$bytes = pack("C*", ...[0x1a, 0x0c, 0x08, 0x96, 0x01, 0x12, 0x07, 0x74, 0x65, 0x73, 0x74, 0x69, 0x6e, 0x67]);

$test1msg = new ProtoBufMessage('Test1');
$test1msg->define_field('a', 'int32', 1);
$test1msg->define_field('b', 'string', 2);
$test2msg = new ProtoBufMessage('Test2');
$test2msg->register_message($test1msg);
$test2msg->define_field('c', 'Test1', 3);
$test2 = $test2msg->decode($bytes);

print_r($test2);
/*
Array
(
    [@type] => Test2
    [c] => Array
        (
            [@type] => Test1
            [a] => 150
            [b] => testing
        )

)
*/

```
