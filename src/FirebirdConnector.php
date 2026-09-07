<?php

namespace HarryGulliford\Firebird;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;

class FirebirdConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * @param  array  $config
     * @return \PDO
     */
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);

        $options = $this->getOptions($config);

        $connection = $this->createConnection($dsn, $config, $options);

        $connection->exec('SET BIND OF TIME ZONE TO VARCHAR');

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        $dsn = "firebird:dbname={$config['host']}";

        if (isset($config['port'])) {
            $dsn .= "/{$config['port']}";
        }

        $dsn .= ":{$config['database']};";

        if (isset($config['role'])) {
            $dsn .= "role={$config['role']};";
        }

        if (isset($config['charset'])) {
            $dsn .= "charset={$config['charset']};";
        }

        return $dsn;
    }
}
