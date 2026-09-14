<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

class RawExpressionTypingTest extends TestCase
{
    private function withTable(callable $test): void
    {
        try {
            Schema::create('raw_typing_items', function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->integer('value');
            });
            DB::table('raw_typing_items')->insert(['id' => 1, 'value' => 10]);
            DB::table('raw_typing_items')->insert(['id' => 2, 'value' => 20]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $test();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists('raw_typing_items');
        }
    }

    #[Test]
    public function it_reports_an_unknown_type_for_an_untyped_raw_select_parameter(): void
    {
        $this->withTable(function () {
            try {
                DB::table('raw_typing_items')->selectRaw('"value" + ? AS "calc"', [1])->get();
                $this->fail('Firebird must reject the untyped arithmetic parameter.');
            } catch (QueryException $e) {
                $this->assertInstanceOf(PDOException::class, $e->getPrevious());
                // SQLSTATE varies with the Firebird client; SQLCODE identifies this failure.
                $this->assertSame(-804, (int) $e->getPrevious()->errorInfo[1]);
                $this->assertSame([1], $e->getBindings());
                $this->assertSame('select "value" + ? AS "calc" from "raw_typing_items"', $e->getSql());
            }
            $this->assertSame([10, 20], DB::table('raw_typing_items')->orderBy('id')->pluck('value')->all());
        });
    }

    #[Test]
    public function it_types_a_raw_select_parameter_with_an_explicit_cast(): void
    {
        $this->withTable(function () {
            $rows = DB::table('raw_typing_items')
                ->selectRaw('"value" + CAST(? AS INTEGER) AS "calc"', [1])->orderBy('id')->get();
            $this->assertSame([11, 21], $rows->pluck('calc')->all());
            $this->assertSame([1], DB::getQueryLog()[0]['bindings']);
        });
    }

    #[Test]
    public function it_uses_the_physical_column_type_even_when_the_query_has_an_alias(): void
    {
        $this->withTable(function () {
            $query = DB::table('raw_typing_items as r')->selectRaw(
                '"r"."value" + CAST(? AS TYPE OF COLUMN "raw_typing_items"."value") AS "calc"', [1]
            )->orderBy('r.id');
            $this->assertSame([11, 21], $query->get()->pluck('calc')->all());
            $entry = DB::getQueryLog()[0];
            $this->assertSame([1], $entry['bindings']);
            $this->assertStringContainsString('TYPE OF COLUMN "raw_typing_items"."value"', $entry['query']);
            $this->assertStringContainsString('from "raw_typing_items" as "r"', $entry['query']);
        });
    }

    #[Test]
    public function it_infers_a_raw_comparison_parameter_without_casting(): void
    {
        $this->withTable(function () {
            $rows = DB::table('raw_typing_items')->whereRaw('"value" > ?', [10])->orderBy('id')->get();
            $this->assertSame([2], $rows->pluck('id')->all());
            $this->assertSame([10], DB::getQueryLog()[0]['bindings']);
        });
    }

    #[Test]
    public function it_preserves_select_where_and_order_binding_order(): void
    {
        $this->withTable(function () {
            $query = DB::table('raw_typing_items')
                ->selectRaw('"id", "value" + CAST(? AS INTEGER) AS "calc"', [1])
                ->whereRaw('"value" > ?', [5])
                ->orderByRaw('CASE WHEN "value" = ? THEN 0 ELSE 1 END', [20])->orderBy('id');
            $sql = $query->toSql();
            $this->assertSame([1, 5, 20], $query->getBindings());
            $this->assertSame($sql, $query->toSql());
            $rows = $query->get();
            $this->assertSame([[2, 21], [1, 11]], $rows->map(fn ($row) => [$row->id, $row->calc])->all());
            $entry = DB::getQueryLog()[0];
            $this->assertSame([1, 5, 20], $entry['bindings']);
            $this->assertSame(3, substr_count($entry['query'], '?'));
            $this->assertSame($sql, $entry['query']);
            $this->assertSame($sql, $query->toSql());
            $this->assertSame([1, 5, 20], $query->getBindings());
        });
    }
}
