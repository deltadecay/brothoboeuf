<?php

namespace UtilTests;

require_once(__DIR__."/../src/protobuf.php");

require_once(__DIR__."/../../pest/pest.php");

use function \pest\test;
use function \pest\expect;


use function brothoboeuf\decode_tag;
use function brothoboeuf\zigzag_decode;
use function brothoboeuf\zigzag_encode_sint32;
use function brothoboeuf\zigzag_encode_sint64;
use function brothoboeuf\decode_varint;
use function brothoboeuf\decode_i32;
use function brothoboeuf\decode_i64;
use function brothoboeuf\decode_len;
use function brothoboeuf\decode_value;




test("decode_tag()", function() {
	list($wiretype, $fieldnum) = decode_tag("\x08", 0);
	expect($wiretype)->toBe(0); // VARINT
	expect($fieldnum)->toBe(1); 

	list($wiretype, $fieldnum) = decode_tag("\x0a", 0);
	expect($wiretype)->toBe(2); // LEN
	expect($fieldnum)->toBe(1); 
	
	list($wiretype, $fieldnum) = decode_tag("\x1a", 0);
	expect($wiretype)->toBe(2); // LEN
	expect($fieldnum)->toBe(3); 
});

test("zigzag_decode()", function() {
	expect(zigzag_decode(0))->toBe(0);
	expect(zigzag_decode(1))->toBe(-1);
	expect(zigzag_decode(2))->toBe(1);
	expect(zigzag_decode(3))->toBe(-2);
	expect(zigzag_decode(999))->toBe(-500);
	expect(zigzag_decode(0xfffffffe))->toBe(0x7fffffff);
	expect(zigzag_decode(0xffffffff))->toBe(-0x80000000);
});

test("zigzag_encode_sint32/sint64()", function() {
	expect(zigzag_encode_sint32(0))->toBe(0);
	expect(zigzag_encode_sint32(-1))->toBe(1);
	expect(zigzag_encode_sint32(1))->toBe(2);
	expect(zigzag_encode_sint32(-2))->toBe(3);
	expect(zigzag_encode_sint32(-500))->toBe(999);
	expect(zigzag_encode_sint64(0x7fffffff))->toBe(0xfffffffe);
	expect(zigzag_encode_sint64(-0x80000000))->toBe(0xffffffff);
});


test("decode_varint()", function() {

	// The number 3 is encoded in a single byte with varint encoding
	list($value, $offset) = decode_varint("\x03", 0, ['type' => 'int32']);
	expect($value)->toBe(3); 
	// offset is pointing to the next byte (if any)
	expect($offset)->toBe(1); 
	
	// For a one byte varint, the max value is 127, the 8th bit is not set as it is the continuation bit.
	list($value, $offset) = decode_varint("\x7f", 0, ['type' => 'int32']);
	expect($value)->toBe(127); 
	expect($offset)->toBe(1); 

	// The value of 150 takes two bytes when varint encoded
	list($value, $offset) = decode_varint("\x96\x01", 0, ['type' => 'int32']);
	expect($value)->toBe(150); 
	expect($offset)->toBe(2); 

	// booleans
	list($value, $offset) = decode_varint("\x00", 0, ['type' => 'bool']);
	expect($value)->toBeEqual(false); 
	expect($offset)->toBe(1); 
	list($value, $offset) = decode_varint("\x01", 0, ['type' => 'bool']);
	expect($value)->toBeEqual(true); 
	expect($offset)->toBe(1); 

	// 
	list($value, $offset) = decode_varint("\x01", 0, ['type' => 'int32']);
	expect($value)->toBeEqual(1); 
	expect($offset)->toBe(1); 

	// sint64/sint32 are zigzag encoded so that negative numbers use fewer bytes than a regular negative as two's complements, 
	// which would be up ten bytes for the varint for 64-bit numbers 
	list($value, $offset) = decode_varint("\x01", 0, ['type' => 'sint32']);
	expect($value)->toBeEqual(-1); 
	expect($offset)->toBe(1); 

	list($value, $offset) = decode_varint("\x01", 0, ['type' => 'sint64']);
	expect($value)->toBeEqual(-1); 
	expect($offset)->toBe(1); 
});


test("decode_i32()", function() {
	// echo bin2hex(pack("g", 1.95125e3));
	list($value, $offset) = decode_i32("\x00\xe8\xf3\x44", 0, ['type' => 'float']);
	expect($value)->toBeCloseTo(1951.25, 2); 
	expect($offset)->toBe(4); 

	// echo bin2hex(pack("V", 3735928559))
	list($value, $offset) = decode_i32("\xef\xbe\xad\xde", 0, ['type' => 'fixed32']);
	expect($value)->toBeEqual(0xdeadbeef); 
	expect($offset)->toBe(4); 

	
	// echo bin2hex(pack("V", 3))
	list($value, $offset) = decode_i32("\x03\x00\x00\x00", 0, ['type' => 'fixed32']);
	expect($value)->toBe(3); 
	expect($offset)->toBe(4); 

	// if encoding negative numbers with I32 then sfixed32 must be used. fixed32 cannot properly decode negative.
	// echo bin2hex(pack("V", zigzag_encode_sint32(-2))) 
	list($value, $offset) = decode_i32("\x03\x00\x00\x00", 0, ['type' => 'sfixed32']);
	expect($value)->toBe(-2); 
	expect($offset)->toBe(4); 
});

test("decode_i64()", function() {

});

test("decode_len()", function() {

	// First byte 0x0b is varint encoding of the length of the payload, in this case a string "hello world" 
	list($value, $offset) = decode_len("\x0b\x68\x65\x6c\x6c\x6f\x20\x77\x6f\x72\x6c\x64", 0, ['type' => 'string']);
	expect($value)->toBe("hello world"); 
	expect($offset)->toBe(12); 
});

test("decode_value()", function() {
	$wiretype = 0; // VARINT
	$offset = 1;
	// The first byte 0x01 is the tag encoded as a varint, which means fieldnum 1 and wiretype 0 (VARINT) for the following value.
	// The second and third bytes (0x96 0x01) make the varint for the value 150.
	list($value, $offset) = decode_value("\x01\x96\x01", $offset, $wiretype, ['type' => 'int32']);
	expect($value)->toBe(150); 
	expect($offset)->toBe(3); 

	$wiretype = 2; // LEN
	$offset = 1;
	list($value, $offset) = decode_value("\x0a\x0b\x68\x65\x6c\x6c\x6f\x20\x77\x6f\x72\x6c\x64", $offset, $wiretype, ['type' => 'string']);
	expect($value)->toBe("hello world"); 
	expect($offset)->toBe(13); 
});