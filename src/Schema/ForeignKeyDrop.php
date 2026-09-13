<?php

namespace HarryGulliford\Firebird\Schema;

use Illuminate\Database\Connection;
use LogicException;

/**
 * Resolve a foreign-key name only when preceding schema commands have executed.
 */
class ForeignKeyDrop
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $wrappedTable,
        private string $name,
        private ?string $legacy,
        private array $columns,
    ) {}

    public function execute(): void
    {
        if ($this->connection->pretending()) {
            $this->drop($this->name);

            return;
        }

        // The schema query restricts results to this table and FOREIGN KEY constraints.
        $keys = $this->connection->getSchemaBuilder()->getForeignKeys($this->table);
        foreach (array_unique([$this->name, strtoupper($this->name)]) as $candidate) {
            foreach ($keys as $key) {
                if ($key['name'] === $candidate) {
                    $this->drop($key['name']);

                    return;
                }
            }
        }

        if ($this->legacy !== null) {
            foreach ($keys as $key) {
                if ($key['name'] === $this->legacy && $key['columns'] === $this->columns) {
                    $this->drop($key['name']);

                    return;
                }
            }
        }

        throw new LogicException("Foreign key [{$this->name}] was not found on table [{$this->table}].");
    }

    private function drop(string $name): void
    {
        $identifier = '"'.str_replace('"', '""', $name).'"';
        $this->connection->statement('ALTER TABLE '.$this->wrappedTable.' DROP CONSTRAINT '.$identifier);
    }
}
