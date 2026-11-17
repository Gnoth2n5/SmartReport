<?php

namespace App\Services\DuckDb;

use App\Models\DataSource;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class DuckDbSourceAdapter
{
    /**
     * Map of known table names to qualify when schemas are omitted in queries.
     *
     * @var array<string, list<string>>
     */
    protected const KNOWN_TABLES = [
        DataSource::DRIVER_PGSQL => ['v_epcontract', 'v_epcontract_detail'],
        DataSource::DRIVER_SQLSRV => ['production_orders', 'machine_logs'],
        DataSource::DRIVER_ORACLE => ['EMPLOYEES', 'DEPARTMENTS', 'employees', 'departments'],
    ];

    /**
     * Generate LOAD extension SQL for the driver.
     */
    public function generateLoadExtensionSql(string $driver): string
    {
        return match ($driver) {
            DataSource::DRIVER_PGSQL => 'LOAD postgres;',
            DataSource::DRIVER_SQLSRV => 'LOAD mssql;',
            DataSource::DRIVER_ORACLE => 'LOAD oracle_scanner;',
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}] for DuckDB extension loading."),
        };
    }

    /**
     * Generate ATTACH (and CREATE SECRET if needed) SQL for a DataSource.
     */
    public function generateAttachSql(DataSource $source, ?string $alias = null): string
    {
        $alias = $alias ?: $source->code;

        return match ($source->driver) {
            DataSource::DRIVER_PGSQL => sprintf(
                "ATTACH 'host=%s port=%d user=%s password=%s dbname=%s' AS %s (TYPE postgres, READ_ONLY);",
                $source->host,
                $source->port ?: 5432,
                $source->username,
                $source->password,
                $source->database_name,
                $alias
            ),
            DataSource::DRIVER_SQLSRV => sprintf(
                "ATTACH 'Server=%s,%d;Database=%s;User Id=%s;Password=%s;TrustServerCertificate=yes' AS %s (TYPE mssql);",
                $source->host,
                $source->port ?: 1433,
                $source->database_name,
                $source->username,
                $source->password,
                $alias
            ),
            DataSource::DRIVER_ORACLE => sprintf(
                "CREATE SECRET IF NOT EXISTS %s (TYPE oracle, DSN '%s:%d/%s', USER '%s', PASSWORD '%s');\nATTACH '%s' AS %s (TYPE oracle_scanner);",
                $alias,
                $source->host,
                $source->port ?: 1521,
                $source->service_name ?: 'FREEPDB1',
                $source->username,
                $source->password,
                $alias,
                $alias
            ),
            default => throw new InvalidArgumentException("Unsupported driver [{$source->driver}] for DataSource [{$source->code}]."),
        };
    }

    /**
     * Generate DETACH SQL for a DataSource alias.
     */
    public function generateDetachSql(DataSource|string $sourceOrAlias): string
    {
        $alias = is_string($sourceOrAlias) ? $sourceOrAlias : $sourceOrAlias->code;

        return "DETACH {$alias};";
    }

    /**
     * Bind parameters safely into SQL. Handles @today, string quoting, numeric casting, and literal NULL.
     *
     * @param  array<string, mixed>  $params
     */
    public function bindParameters(string $sql, array $params): string
    {
        // Normalize parameter names: strip leading @ and lowercase for matching
        $normalized = [];
        foreach ($params as $key => $val) {
            $cleanKey = ltrim((string) $key, '@');
            $normalized[strtolower($cleanKey)] = $val;
            $normalized[$cleanKey] = $val;
        }

        // Match string literals, comments, or @parameters in token order so literals and comments are preserved
        $pattern = '/(\'(?:[^\'\\\\]|\\\\.|(?:\'\'))*\')|(--[^\r\n]*|\/\*[\s\S]*?\*\/)|@([a-zA-Z0-9_]+)\b/';

        return (string) preg_replace_callback($pattern, function ($matches) use ($normalized) {
            // Group 1: string literal — preserve verbatim
            if (isset($matches[1]) && $matches[1] !== '') {
                return $matches[1];
            }

            // Group 2: comment — preserve verbatim
            if (isset($matches[2]) && $matches[2] !== '') {
                return $matches[2];
            }

            // Group 3: parameter name
            if (! isset($matches[3]) || $matches[3] === '') {
                return $matches[0];
            }

            $paramName = $matches[3];
            $lower = strtolower($paramName);

            // Special case: @today with no explicit value passed defaults to current date
            if ($lower === 'today' && ! array_key_exists($lower, $normalized) && ! array_key_exists($paramName, $normalized)) {
                return "'".date('Y-m-d')."'";
            }

            $val = null;
            if (array_key_exists($paramName, $normalized)) {
                $val = $normalized[$paramName];
            } elseif (array_key_exists($lower, $normalized)) {
                $val = $normalized[$lower];
            }

            // Bind literal NULL if value is null, empty string, or string "NULL"
            if ($val === null || $val === '' || (is_string($val) && strtoupper(trim($val)) === 'NULL')) {
                return 'NULL';
            }

            // Boolean
            if (is_bool($val)) {
                return $val ? 'TRUE' : 'FALSE';
            }

            // Numeric types
            if (is_int($val) || is_float($val)) {
                return (string) $val;
            }

            // If string is strictly numeric and token name hints at number (Qty, Amount, Price, Cost, Num)
            if (is_string($val) && is_numeric($val) && preg_match('/(Qty|Amount|Price|Cost|Num)/i', $paramName)) {
                return (string) $val;
            }

            // String quoting: escape single quotes by doubling them
            $escaped = str_replace("'", "''", (string) $val);

            return "'{$escaped}'";
        }, $sql);
    }

    /**
     * Qualify schema and table references in SQL with the source alias catalog.
     */
    public function qualifySqlForSource(string $sql, DataSource $source, ?string $alias = null): string
    {
        $alias = $alias ?: $source->code;
        $schema = $source->default_schema;

        $qualified = $sql;

        // 1. If default schema is present, qualify schema references: public.table -> alias.public.table
        if (! empty($schema)) {
            $pattern = '/(?<![a-zA-Z0-9_.])('.preg_quote($schema, '/').'\.)/i';
            $qualified = (string) preg_replace($pattern, "{$alias}.$1", $qualified);
        }

        // 2. If table name appears directly without schema after FROM / JOIN
        $knownTables = self::KNOWN_TABLES[$source->driver] ?? [];
        foreach ($knownTables as $table) {
            $tablePattern = '/\b(FROM|JOIN)\s+('.preg_quote($table, '/').')\b(?!\.)/i';
            $targetTable = ! empty($schema) ? "{$alias}.{$schema}.{$table}" : "{$alias}.{$table}";
            $qualified = (string) preg_replace($tablePattern, "$1 {$targetTable}", $qualified);
        }

        return $qualified;
    }

    /**
     * Execute a DuckDB command via CLI and return the output or parsed JSON.
     *
     * @return array<mixed>|string
     *
     * @throws RuntimeException
     */
    public function runQuery(string $sql, ?string $dbFile = null, bool $json = true, int $timeout = 60): array|string
    {
        $duckdbBin = config('smartreport.duckdb_binary', 'duckdb');

        $command = [$duckdbBin, '-batch', '-light-mode'];
        if ($dbFile !== null && $dbFile !== '') {
            $command = [$duckdbBin, $dbFile, '-batch', '-light-mode'];
        }

        if ($json) {
            $command[] = '-json';
        }

        $process = new Process($command, null, ['HOME' => $this->resolveHomeDirectory()]);
        $process->setInput($sql);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('DuckDB execution failed: '.$process->getErrorOutput());
        }

        $output = trim($process->getOutput());

        if (! $json) {
            return $output;
        }

        if ($output === '') {
            return [];
        }

        // 1. Try direct JSON decoding
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. If multiple statements run with -json, DuckDB outputs separate top-level JSON arrays.
        // Split top-level arrays: ] followed by [
        $parts = preg_split('/(?<=\])\s*(?=\[)/', $output);
        if (! empty($parts)) {
            $lastBlock = trim(end($parts));
            $decoded = json_decode($lastBlock, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Test live connectivity to a DataSource and report latency in milliseconds.
     *
     * @return array{success: bool, latency_ms: float, message: string}
     */
    public function testConnection(DataSource $source): array
    {
        $start = microtime(true);
        try {
            $alias = '__test_'.$source->code.'_'.bin2hex(random_bytes(4));
            $loadSql = $this->generateLoadExtensionSql($source->driver);
            $attachSql = $this->generateAttachSql($source, $alias);
            $detachSql = $this->generateDetachSql($alias);

            $testSql = <<<SQL
{$loadSql}
{$attachSql}
SELECT 1 AS ok;
{$detachSql}
SQL;

            $this->runQuery($testSql, null, true, 20);
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => true,
                'latency_ms' => $latency,
                'message' => "Successfully connected to {$source->name} [{$source->driver}] in {$latency} ms.",
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => false,
                'latency_ms' => $latency,
                'message' => "Connection failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Mask database passwords/secrets in DuckDB ATTACH and CREATE SECRET statements.
     */
    public function redactSecrets(string $sql): string
    {
        $lines = explode("\n", $sql);

        foreach ($lines as &$line) {
            // 1. Oracle: CREATE SECRET ... (..., PASSWORD 'secret')
            $line = (string) preg_replace(
                '/(?i)(\bPASSWORD\s+\')([^\']*(?:\'\'[^\']*)*)(\')/',
                '$1***$3',
                $line
            );

            // 2. SQL Server: ATTACH 'Server=...;Password=secret;TrustServerCertificate=yes' AS ... (TYPE mssql)
            if (preg_match('/(?i)\bTYPE\s+mssql\b|\bServer\s*=/', $line) && ! preg_match('/(?i)\bTYPE\s+postgres\b/', $line)) {
                if (preg_match('/(?i)\bPassword\s*=.*?;\s*TrustServerCertificate\s*=/', $line)) {
                    $line = (string) preg_replace(
                        '/(?i)(\bPassword\s*=\s*).*?(?=;\s*TrustServerCertificate\s*=)/',
                        '$1***',
                        $line
                    );
                } else {
                    $line = (string) preg_replace(
                        '/(?i)(\bPassword\s*=\s*).*?(?=\'\s+AS\b|\'$|$)/',
                        '$1***',
                        $line
                    );
                }

                continue;
            }

            // 3. PostgreSQL: ATTACH 'host=... user=... password=secret dbname=...' AS ... (TYPE postgres, READ_ONLY)
            if (preg_match('/(?i)\bTYPE\s+postgres\b|\bhost\s*=/', $line)) {
                if (preg_match('/(?i)\bpassword\s*=.*\s+dbname\s*=/', $line)) {
                    $line = (string) preg_replace(
                        '/(?i)(\bpassword\s*=\s*).*?(?=\s+dbname\s*=)/',
                        '$1***',
                        $line
                    );
                } else {
                    $line = (string) preg_replace(
                        '/(?i)(\bpassword\s*=\s*)(?:[^\s\']|\'\')*(?=\s|\'|$)/',
                        '$1***',
                        $line
                    );
                }

                continue;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Resolve a valid HOME directory for DuckDB extension loading under restricted web server environments.
     */
    protected function resolveHomeDirectory(): string
    {
        $envHome = getenv('HOME');
        if (is_string($envHome) && $envHome !== '') {
            return $envHome;
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && ! empty($pw['dir'])) {
                return (string) $pw['dir'];
            }
        }

        return sys_get_temp_dir();
    }
}
