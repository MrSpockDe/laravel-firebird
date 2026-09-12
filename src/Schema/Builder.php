<?php

namespace HarryGulliford\Firebird\Schema;

use Closure;
use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return parent::createBlueprint($table, $callback);
        }

        return new Blueprint($this->connection, $table, $callback);
    }
}
