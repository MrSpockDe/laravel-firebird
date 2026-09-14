<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class GroupLimitTest extends TestCase
{
    #[Test]
    #[DataProvider('selections')]
    public function it_limits_each_group_with_wildcards_or_explicit_columns(?array $columns, bool $alias, int $offset): void
    {
        $this->withTables(function () use ($columns, $alias, $offset) {
            $qualifier = $alias ? 'g' : 'group_limit_items';
            $query = DB::table($alias ? 'group_limit_items AS g' : 'group_limit_items')
                ->orderByDesc($qualifier.'.value')->orderBy($qualifier.'.id')
                ->groupLimit(1, $qualifier.'.parent_id');
            if ($columns !== null) {
                $query->select($columns);
            }
            if ($offset) {
                $query->offset($offset);
            }
            $results = $query->get()->sortBy('parent_id')->values();
            $this->assertCount(2, $results);
            $this->assertSame($offset ? [3, 6] : [2, 5], $results->pluck('id')->all());
            $this->assertSame($offset ? [20, 25] : [30, 35], $results->pluck('value')->all());
            $this->assertSame([1, 2], $results->pluck('parent_id')->all());
            foreach ($results as $row) {
                $this->assertSame(['id', 'parent_id', 'value'], array_keys((array) $row));
            }
            $this->assertSame($columns, $query->columns);
        });
    }

    public static function selections(): iterable
    {
        yield 'implicit wildcard' => [null, false, 0];
        yield 'explicit wildcard' => [['*'], false, 0];
        yield 'wildcard with offset' => [null, false, 1];
        yield 'explicit columns' => [['id', 'parent_id', 'value'], false, 1];
        yield 'qualified wildcard' => [['group_limit_items.*'], false, 1];
        yield 'aliased wildcard' => [null, true, 1];
        yield 'qualified alias' => [['g.*'], true, 0];
    }

    #[Test]
    public function it_eager_loads_a_limited_has_many_relation_for_each_parent(): void
    {
        $this->withTables(function () {
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            $parents = GroupLimitParent::with(['items' => fn ($query) => $query
                ->orderByDesc('value')->orderBy('id')->limit(1)])->orderBy('id')->get();
            $this->assertCount(3, $parents);
            foreach ($parents as $parent) {
                $this->assertTrue($parent->relationLoaded('items'));
            }
            $this->assertSame([2], $parents[0]->items->modelKeys());
            $this->assertSame([5], $parents[1]->items->modelKeys());
            $this->assertCount(0, $parents[2]->items);
            $this->assertInstanceOf(GroupLimitItem::class, $parents[0]->items->first());
            $this->assertSame(1, $parents[0]->items->first()->parent_id);
            $this->assertFalse(array_key_exists('laravel_row', $parents[0]->items->first()->getAttributes()));
            $queries = DB::connection()->getQueryLog();
            $this->assertCount(2, $queries);
            $this->assertStringContainsString('row_number() over (partition by', $queries[1]['query']);
        });
    }

    private function withTables(callable $test): void
    {
        try {
            Schema::create('group_limit_parents', fn (Blueprint $table) => $table->integer('id')->primary());
            Schema::create('group_limit_items', function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->integer('parent_id');
                $table->integer('value');
                $table->foreign('parent_id')->references('id')->on('group_limit_parents');
            });
            foreach ([1, 2, 3] as $id) {
                DB::table('group_limit_parents')->insert(['id' => $id]);
            }
            foreach ([[1, 1, 10], [2, 1, 30], [3, 1, 20], [4, 2, 15], [5, 2, 35], [6, 2, 25]] as [$id, $parent, $value]) {
                DB::table('group_limit_items')->insert(['id' => $id, 'parent_id' => $parent, 'value' => $value]);
            }
            $test();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
            Schema::dropIfExists('group_limit_items');
            Schema::dropIfExists('group_limit_parents');
        }
    }
}

class GroupLimitParent extends Model
{
    protected $table = 'group_limit_parents';
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(GroupLimitItem::class, 'parent_id');
    }
}

class GroupLimitItem extends Model
{
    protected $table = 'group_limit_items';
    public $timestamps = false;
}
