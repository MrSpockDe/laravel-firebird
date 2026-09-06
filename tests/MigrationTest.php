<?php

namespace HarryGulliford\Firebird\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

class MigrationTest extends TestCase
{
    #[Test]
    public function it_drops_a_column()
    {
        Schema::dropIfExists('drop_column_test');

        Schema::create('drop_column_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('obsolete');
        });

        try {
            Schema::table('drop_column_test', function (Blueprint $table) {
                $table->dropColumn('obsolete');
            });

            $this->assertFalse(Schema::hasColumn('drop_column_test', 'obsolete'));
            $this->assertTrue(Schema::hasColumn('drop_column_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_column_test', 'name'));
        } finally {
            Schema::drop('drop_column_test');
        }
    }

    #[Test]
    public function it_drops_multiple_columns()
    {
        Schema::dropIfExists('drop_multiple_columns_test');

        Schema::create('drop_multiple_columns_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('obsolete_one');
            $table->string('obsolete_two');
        });

        try {
            Schema::table('drop_multiple_columns_test', function (Blueprint $table) {
                $table->dropColumn(['obsolete_one', 'obsolete_two']);
            });

            $this->assertFalse(Schema::hasColumn('drop_multiple_columns_test', 'obsolete_one'));
            $this->assertFalse(Schema::hasColumn('drop_multiple_columns_test', 'obsolete_two'));
            $this->assertTrue(Schema::hasColumn('drop_multiple_columns_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_multiple_columns_test', 'name'));
        } finally {
            Schema::drop('drop_multiple_columns_test');
        }
    }

    #[Test]
    public function it_fails_explicitly_when_dropping_a_column_used_by_an_index()
    {
        Schema::dropIfExists('drop_indexed_column_test');

        try {
            Schema::create('drop_indexed_column_test', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('indexed_value');
                $table->index('indexed_value');
            });

            try {
                Schema::table('drop_indexed_column_test', function (Blueprint $table) {
                    $table->dropColumn('indexed_value');
                });

                $this->fail('Dropping an indexed column should throw a QueryException.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception);
            }

            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'indexed_value'));

            $index = DB::selectOne(<<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_indexed_column_test'
                  AND s.RDB$FIELD_NAME = 'indexed_value'
            SQL);

            $this->assertNotNull($index);
            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'id'));
            $this->assertTrue(Schema::hasColumn('drop_indexed_column_test', 'name'));
        } finally {
            Schema::dropIfExists('drop_indexed_column_test');
        }
    }

    #[Test]
    public function it_drops_a_column_and_its_unique_constraint()
    {
        Schema::dropIfExists('drop_unique_column_test');

        try {
            Schema::create('drop_unique_column_test', function (Blueprint $table) {
                $table->id();
                $table->string('unique_value');
                $table->unique('unique_value');
            });

            $constraint = DB::selectOne(<<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                       TRIM(rc.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'unique_value'
            SQL);

            $this->assertNotNull($constraint);
            $this->assertNotNull($constraint->index_name);

            Schema::table('drop_unique_column_test', function (Blueprint $table) {
                $table->dropColumn('unique_value');
            });

            $columns = DB::select(<<<'SQL'
                SELECT TRIM(RDB$FIELD_NAME) AS "name"
                FROM RDB$RELATION_FIELDS
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
            SQL);

            $columnNames = array_column($columns, 'name');
            $this->assertNotContains('unique_value', $columnNames);
            $this->assertContains('id', $columnNames);

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$CONSTRAINT_NAME
                FROM RDB$RELATION_CONSTRAINTS
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND RDB$CONSTRAINT_NAME = ?
            SQL, [$constraint->constraint_name]));

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$INDEX_NAME
                FROM RDB$INDICES
                WHERE RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND RDB$INDEX_NAME = ?
            SQL, [$constraint->index_name]));

            $primaryKey = DB::selectOne(<<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_column_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
                  AND s.RDB$FIELD_NAME = 'id'
            SQL);

            $this->assertNotNull($primaryKey);
        } finally {
            Schema::dropIfExists('drop_unique_column_test');
        }
    }

    #[Test]
    public function it_renames_a_column()
    {
        Schema::dropIfExists('rename_column_test');

        Schema::create('rename_column_test', function (Blueprint $table) {
            $table->id();
            $table->string('old_name');
        });

        try {
            Schema::table('rename_column_test', function (Blueprint $table) {
                $table->renameColumn('old_name', 'new_name');
            });

            $this->assertFalse(Schema::hasColumn('rename_column_test', 'old_name'));
            $this->assertTrue(Schema::hasColumn('rename_column_test', 'new_name'));
        } finally {
            Schema::drop('rename_column_test');
        }
    }

    #[Test]
    public function it_creates_an_identity_primary_key_with_id()
    {
        Schema::dropIfExists('identity_test');

        Schema::create('identity_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type"
            FROM RDB$RELATION_FIELDS rf
            WHERE rf.RDB$RELATION_NAME = 'identity_test'
              AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        $primaryKey = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$INDEX_SEGMENTS seg
            ON seg.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            WHERE rc.RDB$RELATION_NAME = 'identity_test'
            AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
            AND seg.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($primaryKey);

        Schema::drop('identity_test');
    }

    #[Test]
    public function it_creates_an_integer_identity_with_increments()
    {
        Schema::dropIfExists('increments_test');

        Schema::create('increments_test', function (Blueprint $table) {
            $table->increments('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'increments_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        // Firebird field type 8 = INTEGER
        $this->assertSame(8, $column->field_type);

        Schema::drop('increments_test');
    }

    #[Test]
    public function it_creates_a_bigint_identity_with_big_increments()
    {
        Schema::dropIfExists('big_increments_test');

        Schema::create('big_increments_test', function (Blueprint $table) {
            $table->bigIncrements('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                TRIM(rf.RDB$FIELD_NAME) AS "name",
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'big_increments_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertSame('id', $column->name);
        $this->assertNotNull($column->identity_type);

        // Firebird field type 16 = BIGINT
        $this->assertSame(16, $column->field_type);

        Schema::drop('big_increments_test');
    }

    #[Test]
    public function eloquent_receives_the_generated_identity()
    {
        Schema::dropIfExists('identity_model_test');

        Schema::create('identity_model_test', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $model = IdentityTestModel::create([
            'name' => 'Firebird',
        ]);

        $this->assertNotNull($model->id);
        $this->assertIsInt($model->id);
        $this->assertGreaterThan(0, $model->id);

        $this->assertDatabaseHas('identity_model_test', [
            'id' => $model->id,
            'name' => 'Firebird',
        ]);

        Schema::drop('identity_model_test');
    }

    #[Test]
    #[DataProvider('incrementTypes')]
    public function it_creates_other_identity_integer_types(
        string $method,
        int $expectedFieldType
    ) {
        Schema::dropIfExists('increment_type_test');

        Schema::create('increment_type_test', function (Blueprint $table) use ($method) {
            $table->{$method}('id');
        });

        $column = DB::selectOne(<<<'SQL'
            SELECT
                rf.RDB$IDENTITY_TYPE AS "identity_type",
                f.RDB$FIELD_TYPE AS "field_type"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f
            ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = 'increment_type_test'
            AND rf.RDB$FIELD_NAME = 'id'
        SQL);

        $this->assertNotNull($column);
        $this->assertNotNull($column->identity_type);
        $this->assertSame($expectedFieldType, $column->field_type);

        Schema::drop('increment_type_test');
    }

    public static function incrementTypes(): array
    {
        return [
            'tiny increments' => ['tinyIncrements', 7],
            'small increments' => ['smallIncrements', 7],
            'medium increments' => ['mediumIncrements', 8],
        ];
    }
}

class IdentityTestModel extends Model
{
    protected $table = 'identity_model_test';

    public $timestamps = false;

    protected $fillable = ['name'];
}
