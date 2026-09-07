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

    #[Test]
    public function it_fails_explicitly_when_changing_a_blob_column_to_integer()
    {
        Schema::dropIfExists('change_incompatible_test');

        try {
            Schema::create('change_incompatible_test', function (Blueprint $table) {
                $table->id();
                $table->binary('value');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "field_type"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_incompatible_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            // Firebird field type 261 = BLOB.
            $this->assertSame(261, $column->field_type);

            try {
                Schema::table('change_incompatible_test', function (Blueprint $table) {
                    $table->integer('value')->change();
                });

                $this->fail('Changing a BLOB column to INTEGER should throw a QueryException.');
            } catch (QueryException $exception) {
                $this->assertInstanceOf(QueryException::class, $exception);
            }

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(261, $column->field_type);
        } finally {
            Schema::dropIfExists('change_incompatible_test');
        }
    }

    #[Test]
    public function it_changes_a_column_type()
    {
        Schema::dropIfExists('change_type_test');

        try {
            Schema::create('change_type_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$FIELD_TYPE AS "field_type"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_type_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(8, $column->field_type);

            Schema::table('change_type_test', function (Blueprint $table) {
                $table->bigInteger('value')->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(16, $column->field_type);
        } finally {
            Schema::dropIfExists('change_type_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length()
    {
        Schema::dropIfExists('change_length_test');

        try {
            Schema::create('change_length_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40);
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_length_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);

            Schema::table('change_length_test', function (Blueprint $table) {
                $table->string('value', 100)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
        } finally {
            Schema::dropIfExists('change_length_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length_without_removing_nullable()
    {
        Schema::dropIfExists('change_preserve_nullable_test');

        try {
            Schema::create('change_preserve_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40)->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length",
                       COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_preserve_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);
            $this->assertSame(0, $column->null_flag);

            Schema::table('change_preserve_nullable_test', function (Blueprint $table) {
                $table->string('value', 100)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
            $this->assertSame(0, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_preserve_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_length_without_removing_its_default()
    {
        Schema::dropIfExists('change_preserve_default_test');

        try {
            Schema::create('change_preserve_default_test', function (Blueprint $table) {
                $table->id();
                $table->string('value', 40)->nullable()->default('original');
            });

            $metadataSql = <<<'SQL'
                SELECT f.RDB$CHARACTER_LENGTH AS "character_length",
                       TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_preserve_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(40, $column->character_length);
            $this->assertSame("DEFAULT 'original'", $column->default_source);

            Schema::table('change_preserve_default_test', function (Blueprint $table) {
                $table->string('value', 100)->nullable()->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(100, $column->character_length);
            $this->assertSame("DEFAULT 'original'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_preserve_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_nullable()
    {
        Schema::dropIfExists('change_nullable_test');

        try {
            Schema::create('change_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
            });

            $metadataSql = <<<'SQL'
                SELECT COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(1, $column->null_flag);

            Schema::table('change_nullable_test', function (Blueprint $table) {
                $table->string('value')->nullable()->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(0, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_not_nullable()
    {
        Schema::dropIfExists('change_not_nullable_test');

        try {
            Schema::create('change_not_nullable_test', function (Blueprint $table) {
                $table->id();
                $table->string('value')->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_not_nullable_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(0, $column->null_flag);

            Schema::table('change_not_nullable_test', function (Blueprint $table) {
                $table->string('value')->nullable(false)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(1, $column->null_flag);
        } finally {
            Schema::dropIfExists('change_not_nullable_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_set_a_default()
    {
        Schema::dropIfExists('change_set_default_test');

        try {
            Schema::create('change_set_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable();
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_set_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(null, $column->default_source);

            Schema::table('change_set_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(10)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_set_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_update_a_default()
    {
        Schema::dropIfExists('change_update_default_test');

        try {
            Schema::create('change_update_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable()->default(10);
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_update_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);

            Schema::table('change_update_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(20)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '20'", $column->default_source);
        } finally {
            Schema::dropIfExists('change_update_default_test');
        }
    }

    #[Test]
    public function it_changes_a_column_to_remove_a_default()
    {
        Schema::dropIfExists('change_remove_default_test');

        try {
            Schema::create('change_remove_default_test', function (Blueprint $table) {
                $table->id();
                $table->integer('value')->nullable()->default(10);
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(CAST(rf.RDB$DEFAULT_SOURCE AS VARCHAR(255))) AS "default_source"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'change_remove_default_test'
                  AND rf.RDB$FIELD_NAME = 'value'
            SQL;

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame("DEFAULT '10'", $column->default_source);

            Schema::table('change_remove_default_test', function (Blueprint $table) {
                $table->integer('value')->nullable()->default(null)->change();
            });

            $column = DB::selectOne($metadataSql);
            $this->assertNotNull($column);
            $this->assertSame(null, $column->default_source);
        } finally {
            Schema::dropIfExists('change_remove_default_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_a_generated_name()
    {
        Schema::dropIfExists('drop_index_test');

        try {
            Schema::create('drop_index_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->index('value');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_index_test'
                  AND i.RDB$INDEX_NAME = 'drop_index_test_value_index'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_index_test', function (Blueprint $table) {
                $table->dropIndex(['value']);
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_index_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_an_explicit_name()
    {
        Schema::dropIfExists('drop_named_index_test');

        try {
            Schema::create('drop_named_index_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->index('value', 'explicit_value_index');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name"
                FROM RDB$INDICES i
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                WHERE i.RDB$RELATION_NAME = 'drop_named_index_test'
                  AND i.RDB$INDEX_NAME = 'explicit_value_index'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_named_index_test', function (Blueprint $table) {
                $table->dropIndex('explicit_value_index');
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_named_index_test');
        }
    }

    #[Test]
    public function it_drops_a_unique_constraint()
    {
        Schema::dropIfExists('drop_unique_test');

        try {
            Schema::create('drop_unique_test', function (Blueprint $table) {
                $table->id();
                $table->string('value');
                $table->unique('value');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_unique_test'
                  AND rc.RDB$CONSTRAINT_NAME = 'drop_unique_test_value_unique'
                  AND rc.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'value'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_unique_test', function (Blueprint $table) {
                $table->dropUnique(['value']);
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_unique_test');
        }
    }

    #[Test]
    public function it_drops_a_primary_key()
    {
        Schema::dropIfExists('drop_primary_test');

        try {
            Schema::create('drop_primary_test', function (Blueprint $table) {
                $table->integer('id');
                $table->primary('id');
            });

            $metadataSql = <<<'SQL'
                SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name"
                FROM RDB$RELATION_CONSTRAINTS rc
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
                WHERE rc.RDB$RELATION_NAME = 'drop_primary_test'
                  AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
                  AND s.RDB$FIELD_NAME = 'id'
            SQL;

            $this->assertNotNull(DB::selectOne($metadataSql));

            Schema::table('drop_primary_test', function (Blueprint $table) {
                $table->dropPrimary();
            });

            $this->assertNull(DB::selectOne($metadataSql));
        } finally {
            Schema::dropIfExists('drop_primary_test');
        }
    }

    #[Test]
    public function it_drops_an_index_with_a_long_generated_name()
    {
        Schema::dropIfExists('drop_long_index_test');
        $generatedName = null;

        try {
            Schema::create('drop_long_index_test', function (Blueprint $table) use (&$generatedName) {
                $table->id();
                $table->string('long_identifier_value');
                $generatedName = $table->index('long_identifier_value')->index;
            });

            $this->assertGreaterThan(31, strlen($generatedName));

            $object = DB::selectOne(<<<'SQL'
                SELECT TRIM(o.RDB$INDEX_NAME) AS "object_name"
                FROM RDB$INDICES o
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = o.RDB$INDEX_NAME
                WHERE o.RDB$RELATION_NAME = 'drop_long_index_test'
                  AND s.RDB$FIELD_NAME = 'long_identifier_value'
            SQL);

            $this->assertNotNull($object);
            $this->assertSame(substr($generatedName, 0, 31), $object->object_name);

            Schema::table('drop_long_index_test', function (Blueprint $table) {
                $table->dropIndex(['long_identifier_value']);
            });

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$INDEX_NAME
                FROM RDB$INDICES
                WHERE RDB$RELATION_NAME = 'drop_long_index_test'
                  AND RDB$INDEX_NAME = ?
            SQL, [$object->object_name]));
        } finally {
            Schema::dropIfExists('drop_long_index_test');
        }
    }

    #[Test]
    public function it_drops_a_unique_constraint_with_a_long_generated_name()
    {
        Schema::dropIfExists('drop_long_unique_test');
        $generatedName = null;

        try {
            Schema::create('drop_long_unique_test', function (Blueprint $table) use (&$generatedName) {
                $table->id();
                $table->string('long_identifier_value');
                $generatedName = $table->unique('long_identifier_value')->index;
            });

            $this->assertGreaterThan(31, strlen($generatedName));

            $object = DB::selectOne(<<<'SQL'
                SELECT TRIM(o.RDB$CONSTRAINT_NAME) AS "object_name"
                FROM RDB$RELATION_CONSTRAINTS o
                JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = o.RDB$INDEX_NAME
                WHERE o.RDB$RELATION_NAME = 'drop_long_unique_test'
                  AND o.RDB$CONSTRAINT_TYPE = 'UNIQUE'
                  AND s.RDB$FIELD_NAME = 'long_identifier_value'
            SQL);

            $this->assertNotNull($object);
            $this->assertSame(substr($generatedName, 0, 31), $object->object_name);

            Schema::table('drop_long_unique_test', function (Blueprint $table) {
                $table->dropUnique(['long_identifier_value']);
            });

            $this->assertNull(DB::selectOne(<<<'SQL'
                SELECT RDB$CONSTRAINT_NAME
                FROM RDB$RELATION_CONSTRAINTS
                WHERE RDB$RELATION_NAME = 'drop_long_unique_test'
                  AND RDB$CONSTRAINT_NAME = ?
            SQL, [$object->object_name]));
        } finally {
            Schema::dropIfExists('drop_long_unique_test');
        }
    }

    #[Test]
    public function it_introspects_a_computed_column()
    {
        Schema::dropIfExists('computed_column_test');

        try {
            DB::statement(<<<'SQL'
                CREATE TABLE "computed_column_test" (
                    "quantity" INTEGER NOT NULL,
                    "price" INTEGER NOT NULL,
                    "total" BIGINT COMPUTED BY ("quantity" * "price")
                )
            SQL);

            $columns = array_column(Schema::getColumns('computed_column_test'), null, 'name');
            $this->assertArrayHasKey('total', $columns);
            $column = $columns['total'];

            $this->assertSame('total', $column['name']);
            $this->assertSame('bigint', $column['type_name']);
            $this->assertSame('bigint', $column['type']);
            $this->assertTrue($column['nullable']);
            $this->assertFalse($column['auto_increment']);
            $this->assertNotNull($column['generation']);
            $this->assertIsArray($column['generation']);
            $this->assertSame('virtual', $column['generation']['type']);
            $this->assertIsString($column['generation']['expression']);
            $this->assertStringContainsString('"quantity" * "price"', $column['generation']['expression']);

            $metadata = DB::selectOne(<<<'SQL'
                SELECT f.RDB$COMPUTED_SOURCE AS "expression",
                       COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag"
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                WHERE rf.RDB$RELATION_NAME = 'computed_column_test'
                  AND rf.RDB$FIELD_NAME = 'total'
            SQL);

            $this->assertNotNull($metadata);
            $this->assertSame($metadata->expression, $column['generation']['expression']);
            $this->assertSame(0, $metadata->null_flag);
            $this->assertSame('bigint', Schema::getColumnType('computed_column_test', 'total'));
            $this->assertSame('bigint', Schema::getColumnType('computed_column_test', 'total', true));
        } finally {
            Schema::dropIfExists('computed_column_test');
        }
    }

    #[Test]
    public function it_introspects_column_metadata()
    {
        Schema::dropIfExists('column_introspection_test');

        try {
            $this->createColumnIntrospectionTable('column_introspection_test');

            $columns = Schema::getColumns('column_introspection_test');
            $this->assertSame(
                ['id', 'name', 'quantity', 'notes', 'status', 'body', 'recorded_at'],
                array_column($columns, 'name'),
            );

            foreach ($columns as $column) {
                $this->assertIsArray($column);
                foreach (['name', 'type_name', 'type', 'collation', 'nullable', 'default', 'auto_increment', 'comment', 'generation'] as $key) {
                    $this->assertArrayHasKey($key, $column, "Missing {$key} for {$column['name']}");
                }

                $this->assertIsString($column['type_name']);
                $this->assertNotSame('', $column['type_name']);
                $this->assertIsString($column['type']);
                $this->assertNotSame('', $column['type']);
                $this->assertSame(in_array($column['name'], ['notes', 'status'], true), $column['nullable']);
                $this->assertSame($column['name'] === 'id', $column['auto_increment']);
                // Identity is reported via auto_increment; none of these columns is computed.
                $this->assertNull($column['generation']);

                if ($column['name'] === 'status') {
                    $this->assertSame("'pending'", $column['default']);
                } else {
                    $this->assertNull($column['default']);
                }
            }

            $byName = array_column($columns, null, 'name');
            foreach (self::introspectionColumnTypes() as [$name, $type]) {
                $this->assertSame($type, strtolower($byName[$name]['type_name']));
            }
            $this->assertSame('varchar(40)', strtolower($byName['name']['type']));
        } finally {
            Schema::dropIfExists('column_introspection_test');
        }
    }

    #[Test]
    #[DataProvider('introspectionColumnTypes')]
    public function it_introspects_column_type(string $column, string $expectedType)
    {
        Schema::dropIfExists('column_type_test');

        try {
            $this->createColumnIntrospectionTable('column_type_test');

            $type = Schema::getColumnType('column_type_test', $column);
            $this->assertIsString($type);
            $this->assertSame($expectedType, strtolower($type));

            $fullType = Schema::getColumnType('column_type_test', $column, true);
            $this->assertIsString($fullType);
            $this->assertNotSame('', $fullType);
            if ($column === 'name') {
                $this->assertSame('varchar(40)', strtolower($fullType));
            }
        } finally {
            Schema::dropIfExists('column_type_test');
        }
    }

    public static function introspectionColumnTypes(): array
    {
        return [
            'identity' => ['id', 'bigint'],
            'string' => ['name', 'varchar'],
            'integer' => ['quantity', 'integer'],
            'nullable' => ['notes', 'varchar'],
            'default' => ['status', 'varchar'],
            'text blob' => ['body', 'blob'],
            'timestamp' => ['recorded_at', 'timestamp'],
        ];
    }

    private function createColumnIntrospectionTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->integer('quantity');
            $table->string('notes')->nullable();
            $table->string('status')->nullable()->default('pending');
            $table->text('body');
            $table->timestamp('recorded_at');
        });
    }

    #[Test]
    #[DataProvider('introspectionIndexKinds')]
    public function it_introspects_indexes(string $kind, array $expectedColumns, bool $unique, bool $primary)
    {
        Schema::dropIfExists('index_introspection_test');

        try {
            $this->createIndexIntrospectionTable('index_introspection_test', $kind);
            $metadata = $this->readIndexIntrospectionMetadata('index_introspection_test');
            $this->assertCount(count($expectedColumns), $metadata);
            $this->assertSame($expectedColumns, array_column($metadata, 'column_name'));

            $indexes = Schema::getIndexes('index_introspection_test');
            $this->assertCount(1, $indexes);
            $index = $indexes[0];
            $this->assertIsArray($index);
            foreach (['name', 'columns', 'type', 'unique', 'primary'] as $key) {
                $this->assertArrayHasKey($key, $index);
            }
            $this->assertSame($metadata[0]->index_name, $index['name']);
            $this->assertSame($expectedColumns, $index['columns']);
            $this->assertSame($unique, $index['unique']);
            $this->assertSame($primary, $index['primary']);
        } finally {
            Schema::dropIfExists('index_introspection_test');
        }
    }

    #[Test]
    #[DataProvider('introspectionIndexKinds')]
    public function it_introspects_index_existence(string $kind, array $expectedColumns, bool $unique, bool $primary)
    {
        Schema::dropIfExists('index_existence_test');

        try {
            $this->createIndexIntrospectionTable('index_existence_test', $kind);
            $metadata = $this->readIndexIntrospectionMetadata('index_existence_test');
            $this->assertNotEmpty($metadata);

            $this->assertTrue(Schema::hasIndex('index_existence_test', $metadata[0]->index_name));
            $this->assertTrue(Schema::hasIndex('index_existence_test', $expectedColumns));
            $this->assertSame($unique, Schema::hasIndex('index_existence_test', $expectedColumns, 'unique'));
            $this->assertSame($primary, Schema::hasIndex('index_existence_test', $expectedColumns, 'primary'));
            $this->assertFalse(Schema::hasIndex('index_existence_test', 'nonexistent_index'));
            $this->assertFalse(Schema::hasIndex('index_existence_test', ['nonexistent_column']));
            if (count($expectedColumns) > 1) {
                $this->assertFalse(Schema::hasIndex('index_existence_test', array_reverse($expectedColumns)));
            }
        } finally {
            Schema::dropIfExists('index_existence_test');
        }
    }

    public static function introspectionIndexKinds(): array
    {
        return [
            'single column' => ['single', ['name'], false, false],
            'composite' => ['composite', ['code', 'name'], false, false],
            'unique' => ['unique', ['code'], true, false],
            'primary key' => ['primary', ['id'], true, true],
        ];
    }

    private function createIndexIntrospectionTable(string $table, string $kind): void
    {
        Schema::create($table, function (Blueprint $table) use ($kind) {
            $table->integer('id');
            $table->string('name');
            $table->string('code');

            match ($kind) {
                'single' => $table->index('name', 'introspect_single_idx'),
                'composite' => $table->index(['code', 'name'], 'introspect_composite_idx'),
                'unique' => $table->unique('code', 'introspect_unique'),
                'primary' => $table->primary('id'),
            };
        });
    }

    private function readIndexIntrospectionMetadata(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(i.RDB$INDEX_NAME) AS "index_name",
                   TRIM(s.RDB$FIELD_NAME) AS "column_name",
                   s.RDB$FIELD_POSITION AS "position",
                   i.RDB$UNIQUE_FLAG AS "unique_flag",
                   i.RDB$INDEX_TYPE AS "index_type",
                   TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                   TRIM(rc.RDB$CONSTRAINT_TYPE) AS "constraint_type"
            FROM RDB$INDICES i
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            LEFT JOIN RDB$RELATION_CONSTRAINTS rc
                ON rc.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                AND rc.RDB$RELATION_NAME = i.RDB$RELATION_NAME
            WHERE i.RDB$RELATION_NAME = ?
            ORDER BY i.RDB$INDEX_NAME, s.RDB$FIELD_POSITION
        SQL, [$table]);
    }

    #[Test]
    #[DataProvider('introspectionForeignKeyKinds')]
    public function it_introspects_foreign_keys(string $kind, array $columns, array $foreignColumns)
    {
        try {
            $this->createForeignKeyIntrospectionTables($kind);
            $metadata = $this->readForeignKeyIntrospectionMetadata();
            $this->assertCount(count($columns), $metadata);
            $this->assertSame($columns, array_column($metadata, 'column_name'));
            $this->assertSame($foreignColumns, array_column($metadata, 'foreign_column'));

            $foreignKeys = Schema::getForeignKeys('fk_intro_child');
            $this->assertCount(1, $foreignKeys);
            $foreignKey = $foreignKeys[0];
            $this->assertIsArray($foreignKey);
            foreach (['name', 'columns', 'foreign_schema', 'foreign_table', 'foreign_columns', 'on_update', 'on_delete'] as $key) {
                $this->assertArrayHasKey($key, $foreignKey);
            }
            $this->assertSame($metadata[0]->constraint_name, $foreignKey['name']);
            $this->assertSame($columns, $foreignKey['columns']);
            $this->assertNull($foreignKey['foreign_schema']);
            $this->assertSame('fk_intro_parent', $foreignKey['foreign_table']);
            $this->assertSame($foreignColumns, $foreignKey['foreign_columns']);
            $this->assertSame(strtolower($metadata[0]->update_rule), $foreignKey['on_update']);
            $this->assertSame(strtolower($metadata[0]->delete_rule), $foreignKey['on_delete']);
            if ($kind === 'delete_cascade') {
                $this->assertSame('cascade', $foreignKey['on_delete']);
            }
            if ($kind === 'update_cascade') {
                $this->assertSame('cascade', $foreignKey['on_update']);
            }
        } finally {
            Schema::dropIfExists('fk_intro_child');
            Schema::dropIfExists('fk_intro_parent');
        }
    }

    #[Test]
    #[DataProvider('introspectionForeignKeyKinds')]
    public function it_introspects_foreign_key_existence(string $kind, array $columns, array $foreignColumns)
    {
        if (! method_exists(Schema::getFacadeRoot(), 'hasForeignKey')) {
            $this->markTestSkipped('This Laravel version does not support Schema::hasForeignKey().');
        }

        try {
            $this->createForeignKeyIntrospectionTables($kind);
            $metadata = $this->readForeignKeyIntrospectionMetadata();
            $this->assertNotEmpty($metadata);

            $this->assertTrue(Schema::hasForeignKey('fk_intro_child', $metadata[0]->constraint_name));
            $this->assertTrue(Schema::hasForeignKey('fk_intro_child', $columns));
            $this->assertFalse(Schema::hasForeignKey('fk_intro_child', 'nonexistent_foreign_key'));
            $this->assertFalse(Schema::hasForeignKey('fk_intro_child', ['nonexistent_column']));
            if (count($columns) > 1) {
                $this->assertFalse(Schema::hasForeignKey('fk_intro_child', array_reverse($columns)));
            }
        } finally {
            Schema::dropIfExists('fk_intro_child');
            Schema::dropIfExists('fk_intro_parent');
        }
    }

    public static function introspectionForeignKeyKinds(): array
    {
        return [
            'simple' => ['simple', ['ref_a'], ['key_a']],
            'delete cascade' => ['delete_cascade', ['ref_a'], ['key_a']],
            'update cascade' => ['update_cascade', ['ref_a'], ['key_a']],
            'composite' => ['composite', ['ref_b', 'ref_a'], ['key_b', 'key_a']],
        ];
    }

    private function createForeignKeyIntrospectionTables(string $kind): void
    {
        Schema::dropIfExists('fk_intro_child');
        Schema::dropIfExists('fk_intro_parent');

        Schema::create('fk_intro_parent', function (Blueprint $table) use ($kind) {
            $table->integer('key_a');
            $table->integer('key_b');
            $table->primary($kind === 'composite' ? ['key_b', 'key_a'] : ['key_a']);
        });
        Schema::create('fk_intro_child', function (Blueprint $table) use ($kind) {
            $table->integer('ref_a');
            $table->integer('ref_b');
            $foreignKey = $table->foreign(
                $kind === 'composite' ? ['ref_b', 'ref_a'] : ['ref_a'],
                'fk_intro_reference',
            )->references($kind === 'composite' ? ['key_b', 'key_a'] : ['key_a'])
                ->on('fk_intro_parent');

            if ($kind === 'delete_cascade') {
                $foreignKey->onDelete('cascade');
            }
            if ($kind === 'update_cascade') {
                $foreignKey->onUpdate('cascade');
            }
        });
    }

    private function readForeignKeyIntrospectionMetadata(): array
    {
        return DB::select(<<<'SQL'
            SELECT TRIM(rc.RDB$CONSTRAINT_NAME) AS "constraint_name",
                   TRIM(rc.RDB$CONSTRAINT_TYPE) AS "constraint_type",
                   TRIM(i.RDB$INDEX_NAME) AS "index_name",
                   TRIM(i.RDB$FOREIGN_KEY) AS "referenced_index",
                   TRIM(s.RDB$FIELD_NAME) AS "column_name",
                   s.RDB$FIELD_POSITION AS "position",
                   TRIM(parent.RDB$CONSTRAINT_NAME) AS "referenced_constraint",
                   TRIM(parent.RDB$RELATION_NAME) AS "foreign_table",
                   TRIM(fs.RDB$FIELD_NAME) AS "foreign_column",
                   fs.RDB$FIELD_POSITION AS "foreign_position",
                   TRIM(ref.RDB$UPDATE_RULE) AS "update_rule",
                   TRIM(ref.RDB$DELETE_RULE) AS "delete_rule"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$REF_CONSTRAINTS ref ON ref.RDB$CONSTRAINT_NAME = rc.RDB$CONSTRAINT_NAME
            JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            JOIN RDB$RELATION_CONSTRAINTS parent ON parent.RDB$CONSTRAINT_NAME = ref.RDB$CONST_NAME_UQ
            JOIN RDB$INDEX_SEGMENTS fs
                ON fs.RDB$INDEX_NAME = parent.RDB$INDEX_NAME
                AND fs.RDB$FIELD_POSITION = s.RDB$FIELD_POSITION
            WHERE rc.RDB$RELATION_NAME = 'fk_intro_child'
              AND rc.RDB$CONSTRAINT_TYPE = 'FOREIGN KEY'
            ORDER BY rc.RDB$CONSTRAINT_NAME, s.RDB$FIELD_POSITION
        SQL);
    }

    #[Test]
    public function it_uses_native_boolean_metadata()
    {
        Schema::dropIfExists('native_boolean_test');

        try {
            Schema::create('native_boolean_test', function (Blueprint $table) {
                $table->id();
                $table->boolean('flag');
            });

            $metadata = $this->readBooleanMetadata('native_boolean_test');
            $this->assertNotNull($metadata);
            $this->assertSame(23, $metadata->field_type);

            $columns = array_column(Schema::getColumns('native_boolean_test'), null, 'name');
            $this->assertSame('boolean', $columns['flag']['type_name']);
            $this->assertSame('boolean', $columns['flag']['type']);
            $this->assertFalse($columns['flag']['nullable']);
            $this->assertSame('boolean', Schema::getColumnType('native_boolean_test', 'flag'));
            $this->assertSame('boolean', Schema::getColumnType('native_boolean_test', 'flag', true));
        } finally {
            Schema::dropIfExists('native_boolean_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanDefaults')]
    public function it_uses_native_boolean_defaults(bool $default)
    {
        Schema::dropIfExists('native_bool_default_test');

        try {
            Schema::create('native_bool_default_test', function (Blueprint $table) use ($default) {
                $table->integer('id');
                $table->boolean('flag')->default($default);
            });

            DB::table('native_bool_default_test')->insert(['id' => 1]);
            $this->assertSame($default, DB::table('native_bool_default_test')->where('id', 1)->value('flag'));
            $metadata = $this->readBooleanMetadata('native_bool_default_test');
            $this->assertNotNull($metadata);
            $this->assertSame(23, $metadata->field_type);
            $this->assertNotNull($metadata->default_source);
        } finally {
            Schema::dropIfExists('native_bool_default_test');
        }
    }

    #[Test]
    public function it_uses_native_boolean_nullable()
    {
        Schema::dropIfExists('native_bool_nullable_test');

        try {
            Schema::create('native_bool_nullable_test', function (Blueprint $table) {
                $table->integer('id');
                $table->boolean('flag')->nullable();
            });

            DB::table('native_bool_nullable_test')->insert(['id' => 1, 'flag' => null]);
            $this->assertNull(DB::table('native_bool_nullable_test')->where('id', 1)->value('flag'));
            $columns = array_column(Schema::getColumns('native_bool_nullable_test'), null, 'name');
            $this->assertTrue($columns['flag']['nullable']);
            $this->assertSame('boolean', $columns['flag']['type_name']);
            $this->assertSame(23, $this->readBooleanMetadata('native_bool_nullable_test')->field_type);
        } finally {
            Schema::dropIfExists('native_bool_nullable_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanInputs')]
    public function it_uses_native_boolean_query_builder_round_trip(bool|int $input, bool $expected)
    {
        Schema::dropIfExists('native_bool_query_test');

        try {
            Schema::create('native_bool_query_test', function (Blueprint $table) {
                $table->integer('id');
                $table->boolean('flag');
            });

            DB::table('native_bool_query_test')->insert(['id' => 1, 'flag' => $input]);
            $value = DB::table('native_bool_query_test')->where('id', 1)->value('flag');
            $this->assertSame($expected, $value);
        } finally {
            Schema::dropIfExists('native_bool_query_test');
        }
    }

    #[Test]
    #[DataProvider('nativeBooleanInputs')]
    public function it_uses_native_boolean_eloquent_round_trip(bool|int $input, bool $expected)
    {
        Schema::dropIfExists('native_bool_model_test');

        try {
            Schema::create('native_bool_model_test', function (Blueprint $table) {
                $table->id();
                $table->boolean('flag');
            });

            $model = NativeBooleanTestModel::create(['flag' => $input])->fresh();
            $this->assertNotNull($model);
            $this->assertSame($expected, $model->flag);
            $this->assertSame($expected, $model->getRawOriginal('flag'));
        } finally {
            Schema::dropIfExists('native_bool_model_test');
        }
    }

    public static function nativeBooleanDefaults(): array
    {
        return ['true' => [true], 'false' => [false]];
    }

    public static function nativeBooleanInputs(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'one' => [1, true],
            'zero' => [0, false],
        ];
    }

    private function readBooleanMetadata(string $table): ?object
    {
        return DB::selectOne(<<<'SQL'
            SELECT TRIM(rf.RDB$FIELD_NAME) AS "name",
                   f.RDB$FIELD_TYPE AS "field_type",
                   f.RDB$FIELD_SUB_TYPE AS "field_sub_type",
                   f.RDB$FIELD_LENGTH AS "field_length",
                   f.RDB$CHARACTER_LENGTH AS "character_length",
                   COALESCE(rf.RDB$NULL_FLAG, 0) AS "null_flag",
                   rf.RDB$DEFAULT_SOURCE AS "default_source"
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ? AND rf.RDB$FIELD_NAME = 'flag'
        SQL, [$table]);
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

class NativeBooleanTestModel extends Model
{
    protected $table = 'native_bool_model_test';

    public $timestamps = false;

    protected $fillable = ['flag'];

    protected $casts = ['flag' => 'boolean'];
}
