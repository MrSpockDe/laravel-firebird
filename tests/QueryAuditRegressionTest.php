<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class QueryAuditRegressionTest extends TestCase
{
    private function withTables(callable $test): void
    {
        try {
            foreach (['audit_source', 'audit_target'] as $name) {
                Schema::create($name, function (Blueprint $table) {
                    $table->id();
                    $table->string('name', 50);
                    $table->integer('value');
                });
            }
            foreach ([[1, 'Alpha', 10], [2, 'Bravo', 20], [3, 'Charlie', 30]] as [$id, $name, $value]) {
                DB::table('audit_source')->insert(compact('id', 'name', 'value'));
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $test();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists('audit_target');
            Schema::dropIfExists('audit_source');
        }
    }

    #[Test]
    public function it_inserts_using_filtered_rows_with_select_bindings_and_reordered_target_columns(): void
    {
        $this->withTables(function () {
            $source = DB::table('audit_source')
                ->selectRaw('"value", CAST(? AS VARCHAR(10)) || "name"', ['Copy: '])
                ->where('value', '>=', 20)->where('value', '<', 40);

            // Target order differs from the table definition (name, value).
            $this->assertSame(2, DB::table('audit_target')->insertUsing(['value', 'name'], $source));
            $entry = DB::getQueryLog()[0];
            $this->assertSame(['Copy: ', 20, 40], $entry['bindings']);
            $this->assertSame(3, substr_count($entry['query'], '?'));
            $this->assertStringContainsString('insert into "audit_target" ("value", "name") select', strtolower($entry['query']));
            $this->assertSame(
                [['Copy: Bravo', 20], ['Copy: Charlie', 30]],
                DB::table('audit_target')->orderBy('value')->get()
                    ->map(fn ($row) => [$row->name, $row->value])->all()
            );
            $this->assertSame(['Alpha', 'Bravo', 'Charlie'], DB::table('audit_source')->orderBy('id')->pluck('name')->all());
            $this->assertSame(['Copy: ', 20, 40], $source->getBindings());
        });
    }

    #[Test]
    #[DataProvider('outerUnionModes')]
    public function it_orders_nested_unions_and_preserves_binding_order(bool $all, array $expected): void
    {
        $this->withTables(function () use ($all, $expected) {
            $deepest = DB::table('audit_source')->select('id', 'value')
                ->where('value', '>=', 20)->where('value', '<=', 30);
            $nested = DB::table('audit_source')->select('id', 'value')->where('value', 20)
                ->unionAll($deepest)->orderByDesc('id');
            $query = DB::table('audit_source')->select('id', 'value')->where('value', 10)
                ->union($nested, $all)->orderByDesc('value')->orderBy('id');
            $bindings = [10, 20, 20, 30];
            $this->assertSame($bindings, $query->getBindings());
            $sql = $query->toSql();
            $this->assertSame(4, substr_count($sql, '?'));
            $this->assertSame($all ? 2 : 1, substr_count(strtolower($sql), 'union all'));
            $this->assertSame(2, substr_count(strtolower($sql), 'as "firebird_union"'));
            $this->assertSame($expected, $query->get()->map(fn ($row) => [$row->id, $row->value])->all());
            $entry = DB::getQueryLog()[0];
            $this->assertSame($bindings, $entry['bindings']);
            $this->assertSame($sql, $entry['query']);
            $this->assertSame($sql, $query->toSql());
            $this->assertSame($bindings, $query->getBindings());
            $this->assertSame([20, 20, 30], $nested->getBindings());
            $this->assertSame([20, 30], $deepest->getBindings());
        });
    }

    public static function outerUnionModes(): iterable
    {
        yield 'UNION with nested UNION ALL' => [false, [[3, 30], [2, 20], [1, 10]]];
        yield 'UNION ALL with nested UNION ALL' => [true, [[3, 30], [2, 20], [2, 20], [1, 10]]];
    }

    #[Test]
    public function it_cross_joins_qualified_columns_and_filters_the_cartesian_product(): void
    {
        $this->withTables(function () {
            DB::table('audit_target')->insert(['id' => 1, 'name' => 'Left', 'value' => 100]);
            DB::table('audit_target')->insert(['id' => 2, 'name' => 'Right', 'value' => 200]);
            DB::flushQueryLog();
            $query = DB::table('audit_source')->crossJoin('audit_target')
                ->select('audit_source.id as source_id', 'audit_target.id as target_id',
                    'audit_source.name as source_name', 'audit_target.name as target_name')
                ->orderBy('audit_source.id')->orderBy('audit_target.id');
            $rows = $query->get();
            $this->assertSame([[1, 1], [1, 2], [2, 1], [2, 2], [3, 1], [3, 2]],
                $rows->map(fn ($row) => [$row->source_id, $row->target_id])->all());
            $this->assertSame(['Alpha', 'Alpha', 'Bravo', 'Bravo', 'Charlie', 'Charlie'], $rows->pluck('source_name')->all());
            $this->assertSame(['Left', 'Right', 'Left', 'Right', 'Left', 'Right'], $rows->pluck('target_name')->all());
            $this->assertSame([], DB::getQueryLog()[0]['bindings']);
            $this->assertStringContainsString('cross join "audit_target"', $query->toSql());

            $filtered = (clone $query)->where('audit_source.value', '>=', 20)->where('audit_target.value', 200);
            $this->assertSame([[2, 2], [3, 2]], $filtered->get()->map(fn ($row) => [$row->source_id, $row->target_id])->all());
            $entry = DB::getQueryLog()[1];
            $this->assertSame([20, 200], $entry['bindings']);
            $this->assertSame(2, substr_count($entry['query'], '?'));
            $this->assertSame([], $query->getBindings());
        });
    }
}
