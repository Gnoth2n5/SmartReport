<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed Database Drivers
    |--------------------------------------------------------------------------
    |
    | SmartReport strictly restricts data sources to the following 3 enterprise
    | database drivers. Any other driver is rejected.
    |
    */
    'allowed_drivers' => [
        'sqlsrv' => 'SQL Server',
        'pgsql' => 'PostgreSQL',
        'oracle' => 'Oracle',
    ],

    /*
    |--------------------------------------------------------------------------
    | DuckDB 2.0 Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the DuckDB CLI binary used for federation, snapshot creation,
    | query testing, and OpenXML Excel / UTF-8 CSV exports.
    |
    */
    'duckdb_binary' => env('DUCKDB_BINARY', file_exists(storage_path('bin/duckdb')) ? storage_path('bin/duckdb') : 'duckdb'),

    /*
    |--------------------------------------------------------------------------
    | Snapshot Storage & Pagination Settings
    |--------------------------------------------------------------------------
    |
    | Snapshots store materialized query results in detached DuckDB files to
    | enable instant pagination and export without re-querying source databases.
    |
    */
    'snapshot_dir' => env('SMARTREPORT_SNAPSHOT_DIR', storage_path('app/snapshots')),
    'snapshot_ttl_minutes' => (int) env('SMARTREPORT_SNAPSHOT_TTL_MINUTES', 30),
    'page_size' => (int) env('SMARTREPORT_PAGE_SIZE', 10),
];
