<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection;

/** A PostgreSQL connection whose query grammar reads JSON paths out of text columns. */
class JsonTextPostgresConnection extends PostgresConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new JsonTextPostgresGrammar($this);
    }
}
