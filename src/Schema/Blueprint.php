<?php

namespace HarryGulliford\Firebird\Schema;

use Illuminate\Database\Schema\Blueprint as BaseBlueprint;

class Blueprint extends BaseBlueprint
{
    public function build()
    {
        foreach ($this->toSql() as $statement) {
            if ($statement instanceof ForeignKeyDrop) {
                $statement->execute();
            } else {
                $this->connection->statement($statement);
            }
        }
    }

    protected function indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null)
    {
        $generated = ! $index;
        $command = parent::indexCommand($type, $columns, $index, $algorithm, $operatorClass);
        $command->firebirdGeneratedName = $generated;
        $command->firebirdOriginalName = $command->index;

        return $command;
    }

    protected function dropIndexCommand($command, $type, $index)
    {
        $generated = is_array($index);
        $command = parent::dropIndexCommand($command, $type, $index);
        $command->firebirdGeneratedName = $generated;

        return $command;
    }
}
