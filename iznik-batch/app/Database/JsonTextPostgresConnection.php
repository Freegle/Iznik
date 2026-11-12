<?php

namespace App\Database;


/** A PostgreSQL connection whose query grammar reads JSON paths out of text columns. */
class JsonTextPostgresConnection extends PostgresBinaryConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new JsonTextPostgresGrammar($this);
    }
}
