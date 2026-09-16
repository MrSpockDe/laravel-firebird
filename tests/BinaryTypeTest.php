<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class BinaryTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('definitions')]
    public function it_creates_and_adds_native_binary_columns(string $operation, ?int $length, bool $fixed, string $ddl, string $type, int $fieldType)
    {
        $name = 'binary_type_test';
        try {
            if ($operation === 'add') {
                Schema::create($name, fn (Blueprint $table) => $table->integer('id')->nullable());
            }
            $definition = fn (Blueprint $table) => $table->binary('data', $length, $fixed);
            $migration = fn () => $operation === 'create'
                ? Schema::create($name, $definition)
                : Schema::table($name, $definition);
            $sql = DB::pretend($migration)[0]['query'];
            $migration();
            $this->assertStringContainsString('"data" '.$ddl.' NOT NULL', $sql);
            $column = collect(Schema::getColumns($name))->firstWhere('name', 'data');
            $this->assertSame($type, $column['type_name']);
            $this->assertFalse($column['nullable']);
            $this->assertSame($length === null ? 'blob sub_type 0' : $type.'(16)', Schema::getColumnType($name, 'data', true));
            $metadata = DB::selectOne(<<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "type", f.RDB$CHARACTER_LENGTH AS "length",
                       TRIM(cs.RDB$CHARACTER_SET_NAME) AS "charset"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                LEFT JOIN RDB$CHARACTER_SETS cs ON cs.RDB$CHARACTER_SET_ID = f.RDB$CHARACTER_SET_ID
                WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'data'
            SQL, [$name]);
            $this->assertSame($fieldType, $metadata->type);
            $this->assertSame($length, $metadata->length);
            if ($length !== null) {
                $this->assertSame('OCTETS', $metadata->charset);
            }

            foreach (["\x00\x80\xffABC\x00", str_repeat('x', 16)] as $value) {
                DB::table($name)->delete();
                DB::table($name)->insert(['data' => $value]);
                $this->assertSame($length !== null && $fixed ? str_pad($value, $length, "\x00") : $value, DB::table($name)->value('data'));
            }
            if ($length === null) {
                DB::table($name)->delete();
                DB::table($name)->insert(['data' => str_repeat('x', 17)]);
                $this->assertSame(str_repeat('x', 17), DB::table($name)->value('data'));
            } else {
                try {
                    DB::table($name)->insert(['data' => str_repeat('x', 17)]);
                    $this->fail('An overlength non-NUL value must be rejected.');
                } catch (QueryException $exception) {
                    $this->assertInstanceOf(\PDOException::class, $exception->getPrevious());
                    $this->assertSame(-303, (int) $exception->getPrevious()->errorInfo[1]);
                }
                $this->assertSame([str_repeat('x', 16)], DB::table($name)->pluck('data')->all());
            }
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function definitions(): iterable
    {
        foreach (['create', 'add'] as $operation) {
            yield "$operation blob" => [$operation, null, false, 'BLOB SUB_TYPE BINARY', 'blob', 261];
            yield "$operation fixed without length" => [$operation, null, true, 'BLOB SUB_TYPE BINARY', 'blob', 261];
            yield "$operation variable" => [$operation, 16, false, 'VARBINARY(16)', 'varchar', 37];
            yield "$operation fixed" => [$operation, 16, true, 'BINARY(16)', 'char', 14];
        }
    }

    #[Test]
    public function it_widens_varbinary_without_losing_data()
    {
        $name = 'binary_change_test';
        $value = "\x00\x80\xffabc";
        try {
            Schema::create($name, fn (Blueprint $table) => $table->binary('data', 16));
            DB::table($name)->insert(['data' => $value]);
            Schema::table($name, fn (Blueprint $table) => $table->binary('data', 32)->change());
            $this->assertSame('varchar(32)', Schema::getColumnType($name, 'data', true));
            $this->assertSame('OCTETS', Schema::getColumns($name)[0]['collation']);
            $this->assertSame($value, DB::table($name)->value('data'));
            DB::table($name)->insert(['data' => str_repeat('x', 32)]);
            $this->assertSame(2, DB::table($name)->count());
        } finally {
            Schema::dropIfExists($name);
        }
    }

    #[Test]
    #[DataProvider('fixedModes')]
    public function it_leaves_unsupported_blob_conversions_to_firebird(bool $fixed)
    {
        $name = 'binary_change_test';
        $value = "\x00\x80\xffabc";
        try {
            Schema::create($name, fn (Blueprint $table) => $table->binary('data'));
            DB::table($name)->insert(['data' => $value]);
            try {
                Schema::table($name, fn (Blueprint $table) => $table->binary('data', 16, $fixed)->change());
                $this->fail('Firebird cannot convert an existing BLOB column.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(\PDOException::class, $exception->getPrevious());
                $this->assertSame(-607, (int) $exception->getPrevious()->errorInfo[1]);
            }
            $this->assertSame('blob', Schema::getColumnType($name, 'data'));
            $this->assertSame($value, DB::table($name)->value('data'));
        } finally {
            Schema::dropIfExists($name);
        }
    }

    public static function fixedModes(): iterable
    {
        yield 'variable' => [false];
        yield 'fixed' => [true];
    }
}
