<?php

namespace HarryGulliford\Firebird\Schema\Grammars;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class FirebirdGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var array
     */
    protected $modifiers = ['Charset', 'Increment', 'Default', 'Nullable', 'Collate'];

    /**
     * The columns available as serials.
     *
     * @var array
     */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * Compile the query to determine if the given table exists.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileTableExists($schema, $table)
    {
        return sprintf(
            'select case when exists (select 1 from rdb$relations where rdb$relation_name = %s and '
            .'rdb$relation_type = 0 and (rdb$system_flag is null or rdb$system_flag = 0)) then 1 else 0 end '
            .'as "exists" from rdb$database',
            $this->quoteString($table),
        );
    }

    /**
     * Compile the query to determine the tables.
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileTables($schema)
    {
        return 'select trim(trailing from rdb$relation_name) as "name" '
            .'from rdb$relations '
            .'where rdb$relation_type = 0 '
            .'and (rdb$system_flag is null or rdb$system_flag = 0) '
            .'order by rdb$relation_name';
    }

    /**
     * Compile the query to determine the indexes and their ordered columns.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileIndexes($schema, $table)
    {
        return sprintf(<<<'SQL'
            SELECT TRIM(TRAILING FROM i.RDB$INDEX_NAME) AS "name",
                   TRIM(TRAILING FROM s.RDB$FIELD_NAME) AS "column_name",
                   'btree' AS "type",
                   COALESCE(i.RDB$UNIQUE_FLAG, 0) AS "unique",
                   CASE WHEN rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY' THEN TRUE ELSE FALSE END AS "primary"
            FROM RDB$INDICES i
            LEFT JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            LEFT JOIN RDB$RELATION_CONSTRAINTS rc
                ON rc.RDB$INDEX_NAME = i.RDB$INDEX_NAME
                AND rc.RDB$RELATION_NAME = i.RDB$RELATION_NAME
            WHERE i.RDB$RELATION_NAME = %s
            ORDER BY i.RDB$INDEX_NAME, s.RDB$FIELD_POSITION
        SQL, $this->quoteString($table));
    }

    /**
     * Compile the query to determine foreign keys and their ordered column pairs.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileForeignKeys($schema, $table)
    {
        return sprintf(<<<'SQL'
            SELECT TRIM(TRAILING FROM rc.RDB$CONSTRAINT_NAME) AS "name",
                   TRIM(TRAILING FROM s.RDB$FIELD_NAME) AS "column_name",
                   TRIM(TRAILING FROM parent.RDB$RELATION_NAME) AS "foreign_table",
                   TRIM(TRAILING FROM fs.RDB$FIELD_NAME) AS "foreign_column",
                   LOWER(TRIM(ref.RDB$UPDATE_RULE)) AS "on_update",
                   LOWER(TRIM(ref.RDB$DELETE_RULE)) AS "on_delete"
            FROM RDB$RELATION_CONSTRAINTS rc
            JOIN RDB$REF_CONSTRAINTS ref ON ref.RDB$CONSTRAINT_NAME = rc.RDB$CONSTRAINT_NAME
            JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
            JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = i.RDB$INDEX_NAME
            JOIN RDB$RELATION_CONSTRAINTS parent ON parent.RDB$CONSTRAINT_NAME = ref.RDB$CONST_NAME_UQ
            JOIN RDB$INDEX_SEGMENTS fs
                ON fs.RDB$INDEX_NAME = parent.RDB$INDEX_NAME
                AND fs.RDB$FIELD_POSITION = s.RDB$FIELD_POSITION
            WHERE rc.RDB$RELATION_NAME = %s
              AND rc.RDB$CONSTRAINT_TYPE = 'FOREIGN KEY'
            ORDER BY rc.RDB$CONSTRAINT_NAME, s.RDB$FIELD_POSITION
        SQL, $this->quoteString($table));
    }

    /**
     * Compile the query to determine the columns.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(<<<'SQL'
            WITH columns_metadata AS (
                SELECT
                    TRIM(TRAILING FROM rf.RDB$FIELD_NAME) AS "name",
                    TRIM(CASE
                        WHEN f.RDB$FIELD_TYPE IN (7, 8, 16, 26) AND f.RDB$FIELD_SUB_TYPE = 1 THEN 'numeric'
                        WHEN f.RDB$FIELD_TYPE IN (7, 8, 16, 26) AND f.RDB$FIELD_SUB_TYPE = 2 THEN 'decimal'
                        ELSE CASE f.RDB$FIELD_TYPE
                            WHEN 7 THEN 'smallint'
                            WHEN 8 THEN 'integer'
                            WHEN 10 THEN 'float'
                            WHEN 12 THEN 'date'
                            WHEN 13 THEN 'time'
                            WHEN 14 THEN 'char'
                            WHEN 16 THEN 'bigint'
                            WHEN 23 THEN 'boolean'
                            WHEN 24 THEN 'decfloat'
                            WHEN 25 THEN 'decfloat'
                            WHEN 26 THEN 'int128'
                            WHEN 27 THEN 'double precision'
                            WHEN 28 THEN 'time with time zone'
                            WHEN 29 THEN 'timestamp with time zone'
                            WHEN 35 THEN 'timestamp'
                            WHEN 37 THEN 'varchar'
                            WHEN 261 THEN 'blob'
                        END
                    END) AS "type_name",
                    f.RDB$FIELD_TYPE AS field_type,
                    f.RDB$FIELD_SUB_TYPE AS field_sub_type,
                    f.RDB$CHARACTER_LENGTH AS char_length_value,
                    f.RDB$FIELD_PRECISION AS field_precision,
                    f.RDB$FIELD_SCALE AS field_scale,
                    TRIM(TRAILING FROM c.RDB$COLLATION_NAME) AS "collation",
                    CASE WHEN COALESCE(rf.RDB$NULL_FLAG, 0) = 1 OR COALESCE(f.RDB$NULL_FLAG, 0) = 1
                        THEN FALSE ELSE TRUE END AS "nullable",
                    TRIM(SUBSTRING(TRIM(COALESCE(rf.RDB$DEFAULT_SOURCE, f.RDB$DEFAULT_SOURCE)) FROM 8)) AS "default",
                    CASE WHEN rf.RDB$IDENTITY_TYPE IS NOT NULL THEN TRUE ELSE FALSE END AS "auto_increment",
                    rf.RDB$DESCRIPTION AS "comment",
                    f.RDB$COMPUTED_SOURCE AS "generation",
                    rf.RDB$FIELD_POSITION AS field_position
                FROM RDB$RELATION_FIELDS rf
                JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
                LEFT JOIN RDB$COLLATIONS c
                    ON c.RDB$CHARACTER_SET_ID = f.RDB$CHARACTER_SET_ID
                    AND c.RDB$COLLATION_ID = COALESCE(rf.RDB$COLLATION_ID, f.RDB$COLLATION_ID)
                WHERE rf.RDB$RELATION_NAME = %s
            )
            SELECT "name", "type_name",
                CASE
                    WHEN "type_name" IN ('numeric', 'decimal') THEN
                        "type_name" || '(' || field_precision || ',' || (-field_scale) || ')'
                    WHEN field_type IN (14, 37) THEN "type_name" || '(' || char_length_value || ')'
                    WHEN field_type = 24 THEN 'decfloat(16)'
                    WHEN field_type = 25 THEN 'decfloat(34)'
                    WHEN field_type = 261 THEN 'blob sub_type ' || COALESCE(field_sub_type, 0)
                    ELSE "type_name"
                END AS "type",
                "collation", "nullable", "default", "auto_increment", "comment", "generation"
            FROM columns_metadata
            ORDER BY field_position
        SQL, $this->quoteString($table));
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * @param  string  $table
     * @return string
     */
    public function compileColumnListing($table)
    {
        return "select trim(rdb\$field_name) as \"column_name\" from rdb\$relation_fields where rdb\$relation_name = '$table'";
    }

    /**
     * Compile a create table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        if ($blueprint->temporary) {
            throw new \LogicException('This database driver does not support temporary tables.');
        }

        $columns = implode(', ', $this->getColumns($blueprint));

        $sql = 'create table '.$this->wrapTable($blueprint)." ($columns)";

        return $sql;
    }

    /**
     * Reject unsupported table renaming.
     *
     * @throws \LogicException
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        throw new \LogicException('This database driver does not support renaming tables.');
    }

    /**
     * Compile a drop table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return sprintf(
            'execute block as begin if (exists(select 1 from rdb$relations where rdb$relation_name = %s and rdb$relation_type = 0 and '
            .'(rdb$system_flag is null or rdb$system_flag = 0))) then execute statement \'drop table %s\'; end',
            $this->quoteString($blueprint->getTable()),
            $this->wrapTable($blueprint)
        );
    }

    /**
     * Compile a column addition command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        return 'ALTER TABLE '.$table.' ADD '.$this->getColumn($blueprint, $command->column);
    }

    /**
     * Compile a change column command into a series of SQL statements.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return list<string>
     */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        $column = $command->column;
        $sql = 'ALTER TABLE '.$this->wrapTable($blueprint).' ALTER COLUMN '.$this->wrap($column);

        $statements = [
            $sql.' TYPE '.$this->getType($column),
        ];

        if (array_key_exists('nullable', $column->getAttributes())) {
            $statements[] = $sql.($column->nullable ? ' DROP NOT NULL' : ' SET NOT NULL');
        }

        if (array_key_exists('default', $column->getAttributes())) {
            $statements[] = $sql.(is_null($column->default)
                ? ' DROP DEFAULT'
                : ' SET'.$this->modifyDefault($blueprint, $column));
        }

        return $statements;
    }

    /**
     * Compile a drop column command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->prefixArray('DROP', $this->wrapArray($command->columns));

        return 'ALTER TABLE '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * Compile a rename column command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command)
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .' ALTER COLUMN '.$this->wrap($command->from).' TO '.$this->wrap($command->to);
    }

    /**
     * Compile a primary key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->columnize($command->columns);

        return 'ALTER TABLE '.$this->wrapTable($blueprint)." ADD PRIMARY KEY ({$columns})";
    }

    /**
     * Compile a unique key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $index = $this->wrap(substr($command->index, 0, 31));

        $columns = $this->columnize($command->columns);

        return "ALTER TABLE {$table} ADD CONSTRAINT {$index} UNIQUE ({$columns})";
    }

    /**
     * Compile a plain index key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        $columns = $this->columnize($command->columns);

        $index = $this->wrap(substr($command->index, 0, 31));

        $table = $this->wrapTable($blueprint);

        return "CREATE INDEX {$index} ON {$table} ($columns)";
    }

    /**
     * Reject unsupported index renaming.
     *
     * @throws \LogicException
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        throw new \LogicException('This database driver does not support renaming indexes.');
    }

    /**
     * Reject unsupported spatial index creation.
     *
     * @throws \LogicException
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command)
    {
        throw new \LogicException('This database driver does not support creating spatial indexes.');
    }

    /**
     * Compile a drop index command.
     *
     * @return string
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return 'DROP INDEX '.$this->wrap(substr($command->index, 0, 31));
    }

    /**
     * Compile a drop unique constraint command.
     *
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .' DROP CONSTRAINT '.$this->wrap(substr($command->index, 0, 31));
    }

    /**
     * Compile a drop primary key command using its Firebird-assigned name.
     *
     * @return string
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->quoteString($this->connection->getTablePrefix().$blueprint->getTable());
        $sql = $this->quoteString('ALTER TABLE '.$this->wrapTable($blueprint).' DROP CONSTRAINT ');

        return <<<SQL
            EXECUTE BLOCK AS
            DECLARE VARIABLE constraint_name VARCHAR(63);
            BEGIN
                SELECT TRIM(RDB\$CONSTRAINT_NAME)
                FROM RDB\$RELATION_CONSTRAINTS
                WHERE RDB\$RELATION_NAME = {$table}
                  AND RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY'
                INTO :constraint_name;
                EXECUTE STATEMENT {$sql} || '"' || REPLACE(constraint_name, '"', '""') || '"';
            END
        SQL;
    }

    /**
     * Compile a foreign key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $on = $this->wrapTable($command->on);

        // We need to prepare several of the elements of the foreign key definition
        // before we can create the SQL, such as wrapping the tables and convert
        // an array of columns to comma-delimited strings for the SQL queries.
        $columns = $this->columnize($command->columns);

        $onColumns = $this->columnize((array) $command->references);

        $fkName = $this->normalizeForeignKeyName($command->index);

        $sql = "ALTER TABLE {$table} ADD CONSTRAINT {$fkName} ";

        $sql .= "FOREIGN KEY ({$columns}) REFERENCES {$on} ({$onColumns})";

        // Once we have the basic foreign key creation statement constructed we can
        // build out the syntax for what should happen on an update or delete of
        // the affected columns, which will get something like "cascade", etc.
        if (! is_null($command->onDelete)) {
            $sql .= " ON DELETE {$command->onDelete}";
        }

        if (! is_null($command->onUpdate)) {
            $sql .= " ON UPDATE {$command->onUpdate}";
        }

        return $sql;
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        $table = $this->wrapTable($blueprint);

        $fkName = $this->normalizeForeignKeyName($command->index);

        return "ALTER TABLE {$table} DROP CONSTRAINT {$fkName}";
    }

    /**
     * Apply the existing foreign key name length convention to create and drop.
     */
    protected function normalizeForeignKeyName(string $name): string
    {
        return substr($name, 0, 31);
    }

    /**
     * Get the SQL for a character set column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyCharset(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->charset)) {
            return ' CHARACTER SET '.$column->charset;
        }
    }

    /**
     * Get the SQL for a collation column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyCollate(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->collation)) {
            return ' COLLATE '.$column->collation;
        }
    }

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        return $column->nullable ? '' : ' NOT NULL';
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if ($column->type === 'boolean' && is_bool($column->default)) {
            return $column->default ? ' DEFAULT TRUE' : ' DEFAULT FALSE';
        }

        if (! is_null($column->default)) {
            return ' DEFAULT '.$this->getDefaultValue($column->default);
        }
    }

    protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
    {
        if (
            in_array($column->type, $this->serials)
            && $column->autoIncrement
        ) {
            return $this->hasCommand($blueprint, 'primary')
                ? ' GENERATED BY DEFAULT AS IDENTITY'
                : ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
        }
    }

    /**
     * Create the column definition for a char type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeChar(Fluent $column)
    {
        return "CHAR({$column->length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeString(Fluent $column)
    {
        return "VARCHAR({$column->length})";
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMediumText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeLongText(Fluent $column)
    {
        return 'BLOB SUB_TYPE TEXT';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeInteger(Fluent $column)
    {
        return 'INTEGER';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column)
    {
        return 'BIGINT';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column)
    {
        return 'INTEGER';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column)
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column)
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeFloat(Fluent $column)
    {
        return 'FLOAT';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDouble(Fluent $column)
    {
        return 'DOUBLE PRECISION';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDecimal(Fluent $column)
    {
        return "DECIMAL({$column->total}, {$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBoolean(Fluent $column)
    {
        return 'BOOLEAN';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeEnum(Fluent $column)
    {
        $allowed = array_map(function ($a) {
            return "'".$a."'";
        }, $column->allowed);

        return "VARCHAR(255) CHECK (\"{$column->name}\" IN (".implode(', ', $allowed).'))';
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJson(Fluent $column)
    {
        return 'VARCHAR(8191)';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJsonb(Fluent $column)
    {
        return 'VARCHAR(8191) CHARACTER SET OCTETS';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDate(Fluent $column)
    {
        return 'DATE';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDateTime(Fluent $column)
    {
        return 'TIMESTAMP';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column)
    {
        return 'TIMESTAMP WITH TIME ZONE';
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTime(Fluent $column)
    {
        return 'TIME';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimeTz(Fluent $column)
    {
        return 'TIME WITH TIME ZONE';
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent) {
            return 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
        }

        return 'TIMESTAMP';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimestampTz(Fluent $column)
    {
        if ($column->useCurrent) {
            return 'TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP';
        }

        return 'TIMESTAMP WITH TIME ZONE';
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBinary(Fluent $column)
    {
        return 'BLOB SUB_TYPE BINARY';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeUuid(Fluent $column)
    {
        return 'CHAR(36)';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeIpAddress(Fluent $column)
    {
        return 'VARCHAR(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column)
    {
        return 'VARCHAR(17)';
    }
}
