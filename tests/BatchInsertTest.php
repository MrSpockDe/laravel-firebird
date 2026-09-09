<?php

namespace HarryGulliford\Firebird\Tests;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class BatchInsertTest extends TestCase
{
    private function withBatchTable(callable $test): void
    {
        Schema::dropIfExists('batch_items');
        try {
            Schema::create('batch_items', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->nullable();
                $table->integer('amount')->nullable();
                $table->integer('quantity')->default(7);
                $table->boolean('flag')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->text('body')->nullable();
                $table->timeTz('clock')->nullable();
                $table->timestampTz('moment')->nullable();
            });
            DB::flushQueryLog();
            DB::enableQueryLog();
            $test();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Schema::dropIfExists('batch_items');
        }
    }

    #[Test]
    #[DataProvider('batchRows')]
    public function it_inserts_multiple_rows_with_ordered_bindings(array $rows, array $expected, array $bindings)
    {
        $this->withBatchTable(function () use ($rows, $expected, $bindings) {
            $this->assertTrue(DB::table('batch_items')->insert($rows));
            $insert = DB::getQueryLog()[0];
            $this->assertSame($bindings, $insert['bindings']);
            $this->assertSame(count($bindings), substr_count($insert['query'], '?'));
            $this->assertSame(count($rows) - 1, substr_count(strtolower($insert['query']), 'union all'));
            $stored = DB::table('batch_items')->orderBy('name')->orderBy('amount')->get();
            $this->assertSame($expected, $stored->map(fn ($row) => [$row->name, $row->amount])->all());
            $this->assertCount(count($rows), $stored->pluck('id')->unique());
            foreach ($stored as $row) {
                $this->assertGreaterThan(0, $row->id);
                $this->assertSame(7, $row->quantity);
            }
        });
    }

    public static function batchRows(): iterable
    {
        yield 'two rows' => [[['name' => 'Alpha', 'amount' => 10], ['name' => 'Bravo', 'amount' => 20]],
            [['Alpha', 10], ['Bravo', 20]], [10, 'Alpha', 20, 'Bravo']];
        yield 'three rows with different key order' => [[['name' => 'Charlie', 'amount' => 30], ['amount' => 10, 'name' => 'Alpha'], ['name' => 'Bravo', 'amount' => 20]],
            [['Alpha', 10], ['Bravo', 20], ['Charlie', 30]], [30, 'Charlie', 10, 'Alpha', 20, 'Bravo']];
        yield 'duplicates' => [[['name' => 'Same', 'amount' => 1], ['amount' => 1, 'name' => 'Same']],
            [['Same', 1], ['Same', 1]], [1, 'Same', 1, 'Same']];
        yield 'null and different string lengths' => [[['name' => null, 'amount' => null], ['name' => 'A', 'amount' => 1], ['name' => 'Much longer text', 'amount' => 2]],
            [[null, null], ['A', 1], ['Much longer text', 2]], [null, null, 1, 'A', 2, 'Much longer text']];
    }

    #[Test]
    public function it_batch_inserts_native_types()
    {
        $this->withBatchTable(function () {
            $rows = [
                ['name' => 'Alpha', 'flag' => true, 'price' => '12.34', 'body' => str_repeat('ä text ', 1000), 'clock' => '12:34:56.1234 +02:00', 'moment' => '2026-01-15 12:34:56.1234 +02:00'],
                ['name' => 'Bravo', 'flag' => false, 'price' => '-0.25', 'body' => 'Short text', 'clock' => '03:04:05.6789 -03:00', 'moment' => '2026-01-15 03:04:05.6789 -03:00'],
            ];
            $this->assertTrue(DB::table('batch_items')->insert($rows));
            $stored = DB::table('batch_items')->orderBy('name')->get();
            $this->assertCount(2, $stored);
            foreach ($stored as $i => $row) {
                $this->assertSame($rows[$i]['flag'], $row->flag);
                $this->assertSame($rows[$i]['price'], (string) $row->price);
                $this->assertSame($rows[$i]['body'], $row->body);
                $this->assertIsString($row->clock);
                $this->assertIsString($row->moment);
                foreach (['clock', 'moment'] as $column) {
                    $prefix = $column === 'clock' ? '2000-01-01 ' : '';
                    $this->assertSame(
                        (new DateTimeImmutable($prefix.$rows[$i][$column]))->format('U.u'),
                        (new DateTimeImmutable($prefix.$row->{$column}))->format('U.u')
                    );
                }
            }
        });
    }

    #[Test]
    public function it_rolls_back_the_entire_batch_on_a_later_constraint_violation()
    {
        $this->withBatchTable(function () {
            Schema::table('batch_items', fn (Blueprint $table) => $table->unique('name'));
            DB::table('batch_items')->insert(['name' => 'Existing']);
            try {
                DB::table('batch_items')->insert([['name' => 'New'], ['name' => 'Existing']]);
                $this->fail('A later duplicate must reject the whole batch.');
            } catch (QueryException $e) {
                $this->assertSame(-803, (int) ($e->errorInfo[1] ?? 0));
            }
            $this->assertSame(['Existing'], DB::table('batch_items')->pluck('name')->all());
        });
    }

    #[Test]
    public function it_preserves_single_row_insert_and_insert_get_id()
    {
        $this->withBatchTable(function () {
            $this->assertTrue(DB::table('batch_items')->insert(['name' => 'Single']));
            $this->assertTrue(DB::table('batch_items')->insert([['name' => 'One-element batch']]));
            $id = DB::table('batch_items')->insertGetId(['name' => 'Identity']);
            foreach (DB::getQueryLog() as $entry) {
                $this->assertStringContainsString(' values (?)', $entry['query']);
                $this->assertStringNotContainsString('union', $entry['query']);
            }
            $this->assertGreaterThan(0, $id);
            $this->assertSame('Identity', DB::table('batch_items')->where('id', $id)->value('name'));
            $this->assertSame(['Identity', 'One-element batch', 'Single'], DB::table('batch_items')->orderBy('name')->pluck('name')->all());
        });
    }

    #[Test]
    public function it_preserves_insert_using()
    {
        $this->withBatchTable(function () {
            DB::table('batch_items')->insert(['name' => 'Source', 'amount' => 10]);
            $this->assertSame(1, DB::table('batch_items')->insertUsing(['name', 'amount'],
                DB::table('batch_items')->select('name', 'amount')->where('name', 'Source')));
            $this->assertSame([['Source', 10], ['Source', 10]], DB::table('batch_items')->get()->map(fn ($row) => [$row->name, $row->amount])->all());
        });
    }

    #[Test]
    public function it_explicitly_rejects_raw_expressions_in_batches()
    {
        $this->withBatchTable(function () {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Raw expressions are not supported in batch inserts.');
            DB::table('batch_items')->insert([['amount' => 1], ['amount' => DB::raw('DEFAULT')]]);
        });
    }

    #[Test]
    #[DataProvider('invalidBatchColumns')]
    public function it_rejects_batches_without_consistent_columns(array $rows)
    {
        $this->withBatchTable(function () use ($rows) {
            try {
                DB::table('batch_items')->insert($rows);
                $this->fail('An unsupported column layout must be rejected explicitly.');
            } catch (LogicException $e) {
                $this->assertSame('Batch inserts require the same non-empty column list in every row.', $e->getMessage());
            }
            $this->assertSame(0, DB::table('batch_items')->count());
        });
    }

    public static function invalidBatchColumns(): iterable
    {
        yield 'empty rows' => [[[], []]];
        yield 'different columns' => [[['name' => 'Alpha'], ['amount' => 1]]];
    }

    #[Test]
    public function it_attaches_multiple_related_models_in_one_batch()
    {
        try {
            Schema::create('batch_owners', fn (Blueprint $table) => $table->id());
            Schema::create('batch_labels', fn (Blueprint $table) => $table->id());
            Schema::create('batch_owner_label', function (Blueprint $table) {
                $table->foreignId('owner_id')->constrained('batch_owners');
                $table->foreignId('label_id')->constrained('batch_labels');
                $table->primary(['owner_id', 'label_id']);
            });
            $owner = new class extends Model {
                protected $table = 'batch_owners';
                public $timestamps = false;
            };
            $label = new class extends Model {
                protected $table = 'batch_labels';
                public $timestamps = false;
            };
            $owner->save();
            $ids = [DB::table('batch_labels')->insertGetId([]), DB::table('batch_labels')->insertGetId([])];
            $relation = $owner->belongsToMany(get_class($label), 'batch_owner_label', 'owner_id', 'label_id');
            $relation->attach($ids);
            $this->assertSame($ids, $relation->orderBy('batch_labels.id')->get()->modelKeys());
            $this->assertSame([$owner->id, $owner->id], DB::table('batch_owner_label')->orderBy('label_id')->pluck('owner_id')->all());
        } finally {
            Schema::dropIfExists('batch_owner_label');
            Schema::dropIfExists('batch_labels');
            Schema::dropIfExists('batch_owners');
        }
    }
}
