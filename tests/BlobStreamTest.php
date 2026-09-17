<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class BlobStreamTest extends TestCase
{
    #[Test]
    #[DataProvider('blobValues')]
    public function it_round_trips_blob_values(string $type, bool $stream)
    {
        $tableName = 'blob_stream_test';
        $resources = [];
        $payload = $type === 'text'
            ? str_repeat("Grüße — café\n", 1000)
            : str_repeat(implode('', array_map('chr', range(0, 255))), 64);

        try {
            Schema::create($tableName, function (Blueprint $table) use ($type) {
                $table->integer('id');
                $table->{$type}('payload');
            });

            foreach ([$payload, $payload."\nupdated"] as $index => $expected) {
                $value = $expected;
                if ($stream) {
                    $value = fopen('php://temp', 'w+b');
                    $this->assertIsResource($value);
                    $resources[] = $value;
                    $this->assertSame(strlen($expected), fwrite($value, $expected));
                    $this->assertTrue(rewind($value));
                }

                if ($index === 0) {
                    $this->assertTrue(DB::table($tableName)->insert(['id' => 1, 'payload' => $value]));
                } else {
                    $this->assertSame(1, DB::table($tableName)->where('id', 1)->update(['payload' => $value]));
                }

                $this->assertSame($expected, DB::table($tableName)->where('id', 1)->value('payload'));
            }
        } finally {
            foreach ($resources as $resource) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
            Schema::dropIfExists($tableName);
        }
    }

    public static function blobValues(): iterable
    {
        yield 'text string' => ['text', false];
        yield 'text stream' => ['text', true];
        yield 'binary string' => ['binary', false];
        yield 'binary stream' => ['binary', true];
    }
}
