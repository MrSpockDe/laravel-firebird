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
