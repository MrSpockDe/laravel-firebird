<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class FetchUsingTest extends TestCase
{
    #[Test]
    public function it_scopes_fetch_using_to_the_query_and_can_restore_default_fetching()
    {
        if (! method_exists(Builder::class, 'fetchUsing')) {
            $this->markTestSkipped('This Laravel version does not support Query Builder fetchUsing().');
        }

        $tableName = 'fetch_using_test';
        $expected = [
            ['id' => 1, 'name' => 'Anna'],
            ['id' => 2, 'name' => null],
        ];

        try {
            Schema::create($tableName, function (Blueprint $table) {
                $table->integer('id');
                $table->string('name')->nullable();
            });
            DB::table($tableName)->insert($expected);

            $query = DB::table($tableName)->select('id', 'name')->where('id', '>', 0)->orderBy('id');
            $normal = $query->get();
            $this->assertCount(2, $normal);
            foreach ($normal as $index => $row) {
                $this->assertInstanceOf(stdClass::class, $row);
                $this->assertSame($expected[$index], (array) $row);
            }

            $this->assertSame($query, $query->fetchUsing(PDO::FETCH_ASSOC));
            $this->assertSame($expected, $query->get()->all());
            $this->assertSame($expected, $query->get()->all());

            // A different builder on the same connection retains normal object fetching.
            $independent = DB::table($tableName)->where('id', 1)->first();
            $this->assertInstanceOf(stdClass::class, $independent);
            $this->assertSame($expected[0], (array) $independent);

            $query->fetchUsing();
            $restored = $query->get();
            $this->assertCount(2, $restored);
            foreach ($restored as $index => $row) {
                $this->assertInstanceOf(stdClass::class, $row);
                $this->assertSame($expected[$index], (array) $row);
            }
        } finally {
            Schema::dropIfExists($tableName);
        }
    }
}
