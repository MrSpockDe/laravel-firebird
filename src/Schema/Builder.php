<?php

namespace HarryGulliford\Firebird\Schema;

use Closure;
use Illuminate\Database\Schema\Builder as BaseBuilder;
use LogicException;
use PDO;
use Throwable;

class Builder extends BaseBuilder
{
    /** Preserve physical Firebird index names, including quoted case and RDB$ names. */
    public function hasIndex($table, $index, $type = null)
    {
        $type = is_null($type) ? $type : strtolower($type);

        foreach ($this->getIndexes($table) as $value) {
            $typeMatches = is_null($type)
                || ($type === 'primary' && $value['primary'])
                || ($type === 'unique' && $value['unique'])
                || $type === $value['type'];

            if (($value['name'] === $index || $value['columns'] === $index) && $typeMatches) {
                return true;
            }
        }

        return false;
    }

    /** Drop user views, starting with views which no other view uses. */
    public function dropAllViews()
    {
        $this->wipe(function () {
            $remaining = array_fill_keys(array_column($this->getViews(), 'name'), true);
            $dependencies = $this->connection->selectFromWriteConnection($this->grammar->compileViewDependencies());
            $ordered = [];

            while ($remaining) {
                $used = [];
                foreach ($dependencies as $dependency) {
                    if (isset($remaining[$dependency->view_name], $remaining[$dependency->relation_name])) {
                        $used[$dependency->relation_name] = true;
                    }
                }
                $ready = array_keys(array_diff_key($remaining, $used));
                sort($ready, SORT_STRING);
                if (! $ready) {
                    throw new LogicException('Cannot drop views with cyclic view dependencies.');
                }
                foreach ($ready as $name) {
                    $ordered[] = $name;
                    unset($remaining[$name]);
                }
            }

            foreach ($ordered as $name) {
                $this->connection->statement($this->grammar->compileWipeView($name));
            }
        });
    }

    /** Drop all user tables, without applying the connection's table prefix. */
    public function dropAllTables()
    {
        $this->wipe(function () {
            $tables = $this->connection->selectFromWriteConnection($this->grammar->compileWipeTables());
            foreach ($tables as $table) {
                // External files are outside this API's ownership. Never silently omit them.
                if (! in_array((int) $table->type, [0, 4, 5], true)) {
                    throw new LogicException('Cannot drop all tables: unsupported Firebird relation type '
                        .$table->type.' for table '.$table->name.'. External tables must be handled explicitly.');
                }
            }
            $names = array_fill_keys(array_column($tables, 'name'), true);
            foreach ($this->connection->selectFromWriteConnection($this->grammar->compileViewDependencies()) as $dependency) {
                if (isset($names[$dependency->relation_name])) {
                    throw new LogicException('Cannot drop tables referenced by views. Call dropAllViews() '
                        .'or use db:wipe --drop-views first. Blocking view: '.$dependency->view_name.'.');
                }
            }
            foreach ($this->connection->selectFromWriteConnection($this->grammar->compileWipeForeignKeys()) as $fk) {
                if (isset($names[$fk->table_name], $names[$fk->parent_name])) {
                    $this->connection->statement($this->grammar->compileWipeForeignKey($fk->table_name, $fk->name));
                }
            }
            foreach ($tables as $table) {
                $this->connection->statement($this->grammar->compileWipeTable($table->name));
            }
        });
    }

    /**
     * Own the actual commit: Firebird can report DDL dependencies only at commit.
     * A savepoint cannot provide this guarantee inside a caller's transaction.
     */
    protected function wipe(Closure $callback): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new LogicException('Cannot wipe Firebird schema objects inside an active transaction.');
        }
        $pdo = $this->connection->getPdo();
        $autocommit = $pdo->getAttribute(PDO::ATTR_AUTOCOMMIT);
        // Before PHP 8.4 PDO reports its implicit, commit-retaining transaction as
        // active too. With autocommit enabled even direct PDO DML is committed;
        // raw PDO transactions on these versions require ATTR_AUTOCOMMIT=false.
        if ($pdo->inTransaction() && (! $autocommit || PHP_VERSION_ID >= 80400)) {
            throw new LogicException('Cannot wipe Firebird schema objects inside an active transaction.');
        }
        $error = null;
        $restoreAutocommit = true;
        try {
            // Ends older pdo_firebird's implicit autocommit transaction, not a user transaction.
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
            $this->connection->beginTransaction();
            $callback();
            $this->connection->commit();
        } catch (Throwable $e) {
            $error = $e;
            try {
                $this->connection->rollBack(0);
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                $restoreAutocommit = false;
                // A broken connection must not retain a misleading Laravel transaction level.
                $this->connection->disconnect();
            }
            throw $e;
        } finally {
            try {
                if ($restoreAutocommit) {
                    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, $autocommit);
                }
            } catch (Throwable $cleanupError) {
                $this->connection->disconnect();
                if ($error === null) {
                    throw $cleanupError;
                }
            }
        }
    }

    protected function createBlueprint($table, ?Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return parent::createBlueprint($table, $callback);
        }

        return new Blueprint($this->connection, $table, $callback);
    }
}
