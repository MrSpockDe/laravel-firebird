<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class IndexExistenceTest extends TestCase
{
    #[Test]
    public function it_finds_the_exact_physical_name_of_an_automatic_primary_index(): void
    {
        try {
            Schema::create('index_name_test', fn (Blueprint $table) => $table->id());
            $index = Schema::getIndexes('index_name_test')[0];

            $this->assertTrue($index['primary']);
            $this->assertNotSame(strtolower($index['name']), $index['name']);
            $this->assertTrue(Schema::hasIndex('index_name_test', $index['name']));
            $this->assertTrue(Schema::hasIndex('index_name_test', $index['name'], 'PRIMARY'));
            $this->assertTrue(Schema::hasIndex('index_name_test', ['id'], 'unique'));
            $this->assertFalse(Schema::hasIndex('index_name_test', strtolower($index['name'])));
        } finally {
            Schema::dropIfExists('index_name_test');
        }
    }

    #[Test]
    public function it_preserves_exact_mixed_case_names_and_ordered_column_matching(): void
    {
        try {
            Schema::create('index_name_test', function (Blueprint $table) {
                $table->integer('first');
                $table->integer('second');
                $table->index(['first', 'second'], 'Index_Mixed');
            });
            $index = Schema::getIndexes('index_name_test')[0];

            $this->assertSame('Index_Mixed', $index['name']);
            $this->assertTrue(Schema::hasIndex('index_name_test', 'Index_Mixed'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'index_mixed'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'INDEX_MIXED'));
            $this->assertTrue(Schema::hasIndex('index_name_test', ['first', 'second']));
            $this->assertFalse(Schema::hasIndex('index_name_test', ['second', 'first']));
            $this->assertTrue(Schema::hasIndex('index_name_test', 'Index_Mixed', 'BTREE'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'Index_Mixed', 'primary'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'Index_Mixed', 'unique'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'Index_Mixed', 'fulltext'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'missing'));
        } finally {
            Schema::dropIfExists('index_name_test');
        }
    }

    #[Test]
    public function it_preserves_unique_and_primary_type_filters(): void
    {
        try {
            Schema::create('index_name_test', function (Blueprint $table) {
                $table->integer('value');
                $table->unique('value', 'Unique_Mixed');
            });

            $this->assertTrue(Schema::hasIndex('index_name_test', 'Unique_Mixed', 'UNIQUE'));
            $this->assertTrue(Schema::hasIndex('index_name_test', ['value'], 'unique'));
            $this->assertTrue(Schema::hasIndex('index_name_test', ['value'], 'btree'));
            $this->assertFalse(Schema::hasIndex('index_name_test', 'Unique_Mixed', 'primary'));
            $this->assertFalse(Schema::hasIndex('index_name_test', ['value'], 'primary'));
        } finally {
            Schema::dropIfExists('index_name_test');
        }
    }
}
