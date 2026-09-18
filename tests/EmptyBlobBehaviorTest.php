<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;

class EmptyBlobBehaviorTest extends TestCase
{
    #[Test]
    public function it_reports_empty_blob_fetch_behavior()
    {
        $name = 'empty_blob_probe';
        $mismatches = [];
        try {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table) {
                $table->integer('id');
                $table->text('txt')->nullable();
                $table->binary('bin')->nullable();
            });
            $pdo = DB::connection()->getPdo();
            fwrite(STDERR, "\nPHP ".PHP_VERSION.' / Laravel '.\Illuminate\Foundation\Application::VERSION."\n");
            fwrite(STDERR, json_encode(['client' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION), 'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'os' => PHP_OS_FAMILY, 'pdo_firebird' => phpversion('pdo_firebird')])."\n");
            foreach (['laravel', 'pdo'] as $path) {
                foreach (['insert', 'update'] as $operation) {
                    foreach (['txt', 'bin'] as $column) {
                        $values = ['null' => null, 'empty string' => '', 'string' => 'abc', 'empty stream' => '', 'stream' => 'abc'];
                        if ($column === 'bin') {
                            $values += ['NUL byte' => "\0", 'bytes' => "\0\x80\xff"];
                        }
                        foreach ($values as $label => $payload) {
                            DB::table($name)->delete();
                            if ($operation === 'update') {
                                DB::table($name)->insert(['id' => 1, $column => 'old']);
                            }
                            $value = $payload;
                            $stream = null;
                            try {
                                if (str_contains($label, 'stream')) {
                                    $stream = fopen('php://temp', 'w+b');
                                    fwrite($stream, $payload);
                                    rewind($stream);
                                    $value = $stream;
                                }
                                $binding = is_resource($value) ? PDO::PARAM_LOB : PDO::PARAM_STR;
                                if ($path === 'laravel') {
                                    if ($operation === 'insert') {
                                        DB::table($name)->insert(['id' => 1, $column => $value]);
                                    } else {
                                        DB::table($name)->where('id', 1)->update([$column => $value]);
                                    }
                                } else {
                                    $sql = $operation === 'insert'
                                        ? 'insert into "'.$name.'" ("id", "'.$column.'") values (1, ?)'
                                        : 'update "'.$name.'" set "'.$column.'" = ? where "id" = 1';
                                    $statement = $pdo->prepare($sql);
                                    $statement->bindValue(1, $value, $binding);
                                    $statement->execute();
                                    $statement->closeCursor();
                                }
                                $sql = 'select "'.$column.'" as "payload", case when "'.$column.'" is null then 1 else 0 end as "is_null", octet_length("'.$column.'") as "bytes" from "'.$name.'" where "id" = 1';
                                if ($path === 'laravel') {
                                    $row = (array) DB::selectOne($sql);
                                } else {
                                    $statement = $pdo->query($sql);
                                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                                    $statement->closeCursor();
                                }
                                $case = "$path/$operation/$column/$label";
                                fwrite(STDERR, json_encode([$case, 'bind' => $binding, 'null' => $row['is_null'], 'bytes' => $row['bytes'], 'php' => get_debug_type($row['payload']), 'hex' => is_string($row['payload']) ? bin2hex($row['payload']) : null])."\n");
                                $this->assertSame($payload === null ? 1 : 0, $row['is_null'], $case);
                                $this->assertSame($payload === null ? null : strlen($payload), $row['bytes'], $case);
                                // Firebird stores empty BLOBs as non-NULL with length 0, but PDO_Firebird returns null:
                                // https://github.com/php/php-src/issues/23758
                                // Accept null or '' until all supported PHP versions contain the upstream fix;
                                // then tighten this expectation to ''. All other values remain strictly checked.
                                if ($payload === '') {
                                    $this->assertTrue(in_array($row['payload'], ['', null], true), $case);
                                } else {
                                    $this->assertSame($payload, $row['payload'], $case);
                                }
                                if ($row['payload'] !== $payload) {
                                    $mismatches[] = $case;
                                }
                            } finally {
                                if (is_resource($stream)) {
                                    fclose($stream);
                                }
                            }
                        }
                    }
                }
            }
            fwrite(STDERR, json_encode(['empty_blob_returned_null' => $mismatches])."\n");
        } finally {
            unset($statement, $pdo);
            DB::disconnect();
            Schema::dropIfExists($name);
        }
    }
}
