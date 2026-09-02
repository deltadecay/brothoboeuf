<?php

namespace ProtoBufTest;

require_once(__DIR__."/../src/protobuf.php");

require_once(__DIR__."/../../pest/pest.php");

use function \pest\test;
use function \pest\expect;


use brothoboeuf\ProtoBufMessage;



test("A message with two fields, int32 and string, decode VARINT and LEN wiretypes", function() {

	//$bytes = "\x08\x96\x01\x12\x07\x74\x65\x73\x74\x69\x6e\x67";
	//$bytes = "\x08\x96\x01\x12\x07testing";
	$bytes = pack("C*", ...[/* id */ 0x08, 0x96, 0x01, /* name */  0x12, 0x07, 0x74, 0x65, 0x73, 0x74, 0x69, 0x6e, 0x67]);
	// Create the following protobuf message
	/* 
	message Test1 {
		int32 id = 1;
		string name = 2;
	}
	*/
	$test1msg = new ProtoBufMessage('Test1');
	$test1msg->define_field('id', 'int32', 1);
	$test1msg->define_field('name', 'string', 2);
	$test1 = $test1msg->decode($bytes);

	expect($test1['@type'])->toBe("Test1");
	expect($test1['id'])->toBe(150);
	expect($test1['name'])->toBe("testing");	
});



test("A message with a sub message, decode LEN wiretype and its payload as a submessage", function() {

	//$bytes = "\x1a\x0c\x08\x96\x01\x12\x07\x74\x65\x73\x74\x69\x6e\x67";
	$bytes = pack("C*", ...[0x1a, 0x0c, 0x08, 0x96, 0x01, 0x12, 0x07, 0x74, 0x65, 0x73, 0x74, 0x69, 0x6e, 0x67]);
	// Create the following protobuf message
	// Test1 is a submessage of Test2
	/*
	message Test1 {
		int32 a = 1;
		string b = 2;
	}
	message Test2 {
		Test1 c = 3;
	}
	*/
	$test1msg = new ProtoBufMessage('Test1');
	$test1msg->define_field('a', 'int32', 1);
	$test1msg->define_field('b', 'string', 2);
	$test2msg = new ProtoBufMessage('Test2');
	$test2msg->register_message($test1msg);
	$test2msg->define_field('c', 'Test1', 3);
	
	$test2 = $test2msg->decode($bytes);

	expect($test2['@type'])->toBe("Test2");
	expect($test2['c']['@type'])->toBe("Test1");
	expect($test2['c']['a'])->toBe(150);	
	expect($test2['c']['b'])->toBe("testing");	
});



test("A message with a repeated packed int32 field, a missing with default and a float, decode LEN for array and I32 for float", function() {
	//$bytes = "\x22\x05\x68\x65\x6c\x6c\x6f\x2a\x03\x01\x02\x03\x3d\x3f\x9e\x04\x19";
	$bytes = pack("C*", ...[0x22, 0x05, 0x68, 0x65, 0x6c, 0x6c, 0x6f, 0x2a, 0x03, 0x01, 0x02, 0x03, 0x3d, 0x3f, 0x9e, 0x04, 0x19]);
	// Create the following protobuf message with a repeated field, and since it is a primitive scalar, it is by default packed,
	// but explicitly specified here in the definition.
	/*
	message Test4 {
		string d = 4;
		repeated int32 e = 5 [packed = true];
		int64 f = 6 [default = 67];
		float g = 7;
	}
	*/
	$test4msg = new ProtoBufMessage('Test4');
	$test4msg->define_field('d', 'string', 4);
	$test4msg->define_repeated_field('e', 'int32', 5, true);
	// This field will be missing a value, thus will get the default
	$test4msg->define_field('f', 'int64', 6, 67);
	$test4msg->define_field('g', 'float', 7);
	$test4 = $test4msg->decode($bytes);
	
	expect($test4['@type'])->toBe("Test4");
	expect($test4['d'])->toBe("hello");
	expect($test4['e'])->toBe([1, 2, 3]);
	// Make sure default is set for the missing field
	expect($test4['f'])->toBe(67);
	expect($test4['g'])->toBeCloseTo(1.2345, 5);
	
});