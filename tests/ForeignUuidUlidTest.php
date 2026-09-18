<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ForeignUuidUlidTest extends TestCase
{
    #[Test]
    #[DataProvider('keys')]
    public function it_constrains_model_uuid_and_ulid_keys(string $type, string $helper, int $length, string $valid, string $missing)
    {
        if (! method_exists(Blueprint::class, $helper)) {
            $this->markTestSkipped("This Laravel version does not support {$helper}().");
        }

        $parent = 'string_key_parents';
        $child = 'string_key_children';
        $model = new ForeignStringParent;
        $model->setTable($parent);
        $column = $model->getForeignKey();

        try {
            Schema::create($parent, fn (Blueprint $table) => $table->{$type}('code')->primary());
            Schema::create($child, fn (Blueprint $table) => $table->{$helper}($model)->constrained());

            $columns = collect(Schema::getColumns($child))->keyBy('name');
            $this->assertTrue($columns->has($column));
            $this->assertSame('char', $columns[$column]['type_name']);
            $this->assertFalse($columns[$column]['nullable']);
            $this->assertSame('char('.$length.')', Schema::getColumnType($child, $column, true));
            $foreignKeys = Schema::getForeignKeys($child);
            $this->assertCount(1, $foreignKeys);
            $this->assertNotEmpty($foreignKeys[0]['name']);
            $this->assertSame([$column], $foreignKeys[0]['columns']);
            $this->assertSame($parent, $foreignKeys[0]['foreign_table']);
            $this->assertSame(['code'], $foreignKeys[0]['foreign_columns']);

            $this->assertTrue(DB::table($parent)->insert(['code' => $valid]));
            $this->assertTrue(DB::table($child)->insert([$column => $valid]));
            $this->assertSame([$valid], DB::table($child)->pluck($column)->all());
            try {
                DB::table($child)->insert([$column => $missing]);
                $this->fail('Firebird must reject a key without a parent.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
                $this->assertSame(-530, (int) $exception->getPrevious()->errorInfo[1]);
            }
            $this->assertSame([$valid], DB::table($child)->pluck($column)->all());
            $this->assertSame([$valid], DB::table($parent)->pluck('code')->all());
        } finally {
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    public static function keys(): iterable
    {
        yield 'UUID' => ['uuid', 'foreignUuidFor', 36, '123e4567-e89b-42d3-a456-426614174000', '123e4567-e89b-42d3-a456-426614174001'];
        yield 'ULID' => ['ulid', 'foreignUlidFor', 26, '01ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAW'];
    }
}

class ForeignStringParent extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;
}
