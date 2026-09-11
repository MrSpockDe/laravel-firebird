<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class FloatPrecisionTest extends TestCase
{
    #[Test]
    #[DataProvider('precisions')]
    public function it_preserves_float_precision(string $mode, ?int $precision, string $ddl, string $type, bool $single)
    {
        $name = 'float_precision_test';
        try {
            $migration = fn () => Schema::create($name, function (Blueprint $table) use ($mode, $precision) {
                if ($mode === 'double') {
                    $table->double('value');
                } elseif ($mode === 'default') {
                    $table->float('value');
                } else {
                    $column = $table->float('value', $precision);
                    $this->assertSame($precision, $column->getAttributes()['precision']);
                }
            });
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $this->assertSame('create table "float_precision_test" ("value" '.$ddl.' NOT NULL)', $sql);
            $this->assertSame($type, Schema::getColumnType($name, 'value'));
            $this->assertSame($type, Schema::getColumnType($name, 'value', true));
            $metadata = DB::selectOne(<<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "type", f.RDB$FIELD_LENGTH AS "bytes"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'value'
            SQL, [$name]);
            $this->assertSame($single ? 10 : 27, $metadata->type);
            $this->assertSame($single ? 4 : 8, $metadata->bytes);
            foreach (['1.23456789012345', '16777217'] as $input) {
                DB::table($name)->delete();
                DB::table($name)->insert(['value' => $input]);
                $actual = (float) DB::table($name)->value('value');
                if ($input === '16777217') {
                    $this->assertSame($single ? 16777216.0 : 16777217.0, $actual);
                } elseif ($single) {
                    $this->assertGreaterThan(1e-9, abs($actual - (float) $input));
                    $this->assertEqualsWithDelta((float) $input, $actual, 1e-6);
                } else {
                    $this->assertEqualsWithDelta((float) $input, $actual, 1e-14);
                }
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function precisions(): iterable
    {
        yield 'default 53' => ['default', null, 'FLOAT(53)', 'double precision', false];
        yield '24' => ['explicit', 24, 'FLOAT(24)', 'float', true];
        yield '25' => ['explicit', 25, 'FLOAT(25)', 'double precision', false];
        yield '8' => ['explicit', 8, 'FLOAT(8)', 'float', true];
        yield '53' => ['explicit', 53, 'FLOAT(53)', 'double precision', false];
        yield 'null' => ['explicit', null, 'FLOAT', 'float', true];
        yield 'double regression' => ['double', null, 'DOUBLE PRECISION', 'double precision', false];
    }

    #[Test]
    #[DataProvider('invalidPrecisions')]
    public function it_does_not_clamp_invalid_float_precision(int $precision)
    {
        $name = 'float_precision_test';
        try {
            $exception = null;
            try {
                Schema::create($name, fn (Blueprint $table) => $table->float('value', $precision));
            } catch (QueryException $caught) {
                $exception = $caught;
            }
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertStringContainsString("FLOAT($precision)", $exception->getSql());
            $this->assertFalse(Schema::hasTable($name));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function invalidPrecisions(): iterable
    {
        yield [0];
        yield [54];
    }
}
