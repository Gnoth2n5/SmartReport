<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasFactory;

    public const DRIVER_SQLSRV = 'sqlsrv';

    public const DRIVER_PGSQL = 'pgsql';

    public const DRIVER_ORACLE = 'oracle';

    public const ALLOWED_DRIVERS = [
        self::DRIVER_SQLSRV,
        self::DRIVER_PGSQL,
        self::DRIVER_ORACLE,
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'data_sources';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'driver',
        'host',
        'port',
        'database_name',
        'service_name',
        'default_schema',
        'username',
        'password',
        'options',
        'is_read_only',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'options' => 'array',
            'is_read_only' => 'boolean',
            'is_active' => 'boolean',
            'port' => 'integer',
        ];
    }
}
