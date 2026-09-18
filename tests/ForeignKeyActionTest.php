<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ForeignKeyActionTest extends TestCase
{
    #[Test]
    #[DataProvider('actions')]
    public function it_applies_foreign_key_actions(string $operation, ?string $helper, string $action)
    {
        $parent = 'action_parents';
        $child = 'action_children';
        try {
            Schema::create($parent, function (Blueprint $table) {
                $table->integer('id')->primary();
            });
            try {
                Schema::create($child, function (Blueprint $table) use ($parent, $helper) {
                    $table->integer('id')->primary();
                    $table->integer('parent_id')->nullable();
                    $foreign = $table->foreign('parent_id')->references('id')->on($parent);
                    if ($helper !== null) {
                        $foreign->{$helper}();
                    }
                });
            } catch (\LogicException $exception) {
                $this->assertSame('restrict', $action);
                $this->assertNotNull($helper);
                $this->assertSame(
                    'Firebird does not support the RESTRICT foreign key action. Use NO ACTION or omit the action instead.',
                    $exception->getMessage()
                );
                $this->assertFalse(Schema::hasTable($child));
                return;
            }
            if ($helper !== null && $action === 'restrict') {
                $this->fail('Explicit RESTRICT must be rejected before DDL execution.');
            }

            $foreign = Schema::getForeignKeys($child)[0];
            $this->assertSame(['parent_id'], $foreign['columns']);
            $this->assertSame($parent, $foreign['foreign_table']);
            $this->assertSame(['id'], $foreign['foreign_columns']);
            $this->assertSame($action, $foreign['on_'.$operation]);

            foreach ([1, 2] as $id) {
                DB::table($parent)->insert(['id' => $id]);
                DB::table($child)->insert(['id' => $id, 'parent_id' => $id]);
            }

            $blocked = in_array($action, ['restrict', 'no action'], true);
            try {
                $query = DB::table($parent)->where('id', 1);
                $affected = $operation === 'delete' ? $query->delete() : $query->update(['id' => 3]);
                $this->assertFalse($blocked, 'A referenced parent must not be changed for this action.');
                $this->assertSame(1, $affected);
            } catch (QueryException $exception) {
                $this->assertTrue($blocked);
                $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
                $this->assertSame(-530, (int) $exception->getPrevious()->errorInfo[1]);
            }

            $this->assertSame(
                $blocked ? [1, 2] : ($operation === 'delete' ? [2] : [2, 3]),
                DB::table($parent)->orderBy('id')->pluck('id')->all()
            );
            $expectedChildren = [['id' => 2, 'parent_id' => 2]];
            if ($blocked || $action === 'set null' || $operation === 'update') {
                array_unshift($expectedChildren, [
                    'id' => 1,
                    'parent_id' => $blocked ? 1 : ($action === 'set null' ? null : 3),
                ]);
            }
            $actual = DB::table($child)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
            $this->assertSame($expectedChildren, $actual);
        } finally {
            // Match the existing cascade tests: release attachment-held requests before DDL.
            DB::disconnect();
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
        }
    }

    public static function actions(): iterable
    {
        foreach (['delete', 'update'] as $operation) {
            foreach (['cascade' => 'cascade', 'restrict' => 'restrict', 'null' => 'set null', 'noAction' => 'no action'] as $helper => $action) {
                yield "$operation $action" => [$operation, $helper.'On'.ucfirst($operation), $action];
            }
            yield "$operation default" => [$operation, null, 'restrict'];
        }
    }
}
