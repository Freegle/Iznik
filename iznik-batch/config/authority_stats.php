<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Quarterly council statistics
    |--------------------------------------------------------------------------
    |
    | The quarterly reminder generates a spreadsheet per authority and emails
    | them to the partnerships inbox for review before they go out to councils.
    */

    // Where the quarterly reminder (with the generated spreadsheets attached) is sent.
    'reminder_recipient' => env('AUTHORITY_STATS_REMINDER_TO', 'partnerships@ilovefreegle.org'),

    // Authorities we produce quarterly stats for. IDs resolve to the council's
    // full name (including any "District (B)" suffix) from the authorities table
    // at run time. Override via a comma-separated AUTHORITY_STATS_IDS.
    'authority_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('AUTHORITY_STATS_IDS', '72467,117233,72572,72764,72899,72950'))
    ))),
];
