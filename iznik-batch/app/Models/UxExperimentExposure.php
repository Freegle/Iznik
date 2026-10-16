<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One time server code used a member's experiment arm to decide what they got.
 *
 * @see ../../database/migrations/2026_10_16_000001_create_ux_experiment_exposures_table.php
 *
 * A plain model, deliberately not Auditable: the table is analytics, written in bulk by the senders,
 * and an audit row for each would double the writes. It exists so the account merge can move a
 * member's rows to the surviving account and so the erasure paths can name the table.
 *
 * An erasure, a merge or an export must never depend on an analytics table, so each asks tableExists()
 * first, because the batch can be live before the SQL has been run.
 *
 * @property int $id
 * @property string $experiment
 * @property string $arm
 * @property int $userid
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $stratum
 * @property \Illuminate\Support\Carbon $exposed_at
 * @property array|null $detail
 */
class UxExperimentExposure extends Model
{
    protected $table = 'ux_experiment_exposures';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'exposed_at' => 'datetime',
        'detail' => 'array',
    ];

    /**
     * Whether the table exists, asked of the write node. Schema::hasTable asks the read node, and a read
     * node that is behind (db2 while it donates the nightly backup) says no for a table the write node has
     * just made. An erasure that believed it would skip its delete without an error and leave the member's
     * rows for the 90 day purge. A connection in a transaction reads from the write node already, so this
     * only matters outside one.
     *
     * @param  string|null  $connection  a connection name; the default one when null
     */
    public static function tableExists(?string $connection = null): bool
    {
        $model = (new static)->setConnection($connection);

        // keep-raw: the schema check Schema::hasTable makes, answered by the write node instead of the read node.
        return $model->getConnection()->selectFromWriteConnection(
            "select 1 from information_schema.tables where table_schema = schema() and table_name = ? and table_type in ('BASE TABLE', 'SYSTEM VERSIONED') limit 1",
            [$model->getTable()]
        ) !== [];
    }
}
