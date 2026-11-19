<?php

namespace App\Services\DuckDb;

use App\Models\DataSource;
use App\Services\Sql\SqlSafetyValidator;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class DuckDbSourceAdapter
{
    private array $secrets = [];

    public function __construct(protected SqlSafetyValidator $validator) {}

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
        $aliasName = $alias ?: $source->code;
        $alias = $this->quoteIdentifier($aliasName);
        $password = (string) $source->password;
        $pgPassword = str_replace(['\\', "'"], ['\\\\', "\\'"], $password);
        foreach ([$password, str_replace("'", "''", $password), $pgPassword, str_replace("'", "''", $pgPassword)] as $secret) {
            if ($secret !== '' && ! in_array($secret, $this->secrets, true)) {
                $this->secrets[] = $secret;
            }
        }
        usort($this->secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        if ($source->driver === DataSource::DRIVER_PGSQL) {
            $values = ['host' => $source->host, 'port' => $source->port ?: 5432, 'user' => $source->username, 'password' => $password, 'dbname' => $source->database_name];
            $parts = [];
            foreach ($values as $key => $value) {
                $value = (string) $value;
                if ($value === '' || preg_match("/[\\\\'\\s]/", $value)) {
                    $value = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
                }
                $parts[] = $key.'='.$value;
            }

            return 'ATTACH '.$this->quoteString(implode(' ', $parts))." AS {$alias} (TYPE postgres, READ_ONLY);";
        }

        return match ($source->driver) {
            DataSource::DRIVER_SQLSRV => sprintf(
                "CREATE SECRET %s (TYPE mssql, HOST %s, PORT %d, DATABASE %s, USER %s, PASSWORD %s, trust_server_certificate true);\nATTACH '' AS %s (TYPE mssql, SECRET %s, READ_ONLY);",
                $alias,
                $this->quoteString($source->host),
                $source->port ?: 1433,
                $this->quoteString((string) $source->database_name),
                $this->quoteString($source->username),
                $this->quoteString($password),
                $alias,
                $alias
            ),
            DataSource::DRIVER_ORACLE => sprintf(
                "CREATE SECRET %s (TYPE oracle, DSN %s, USER %s, PASSWORD %s);\nATTACH %s AS %s (TYPE oracle_scanner, READ_ONLY);",
                $alias,
                $this->quoteString($source->host.':'.($source->port ?: 1521).'/'.($source->service_name ?: 'FREEPDB1')),
                $this->quoteString($source->username),
                $this->quoteString($password),
                $this->quoteString($aliasName),
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

        return 'DETACH '.$this->quoteIdentifier($alias).';';
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

        return $this->validator->tokenizeAndSanitize($sql, stripComments: false, transformCode: function (string $code) use ($normalized): string {
            return (string) preg_replace_callback('/@([a-zA-Z0-9_]+)\b/', function (array $matches) use ($normalized): string {
                $paramName = $matches[1];
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
            }, $code);
        });
    }

    public function generateSearchPathSql(DataSource $source): string
    {
        $path = $this->quoteIdentifier($source->code);
        if (filled($source->default_schema)) {
            $path .= '.'.$this->quoteIdentifier($source->default_schema);
        }

        return 'SET search_path = '.$this->quoteString($path).';';
    }

    public function quoteString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
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
            $error = str_replace($this->secrets, '***', $this->redactSecrets($process->getErrorOutput()));
            throw new RuntimeException('DuckDB execution failed: '.$error);
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
        $sql = (string) preg_replace('/(\bPASSWORD\s+\')([^\']*(?:\'\'[^\']*)*)(\')/is', '$1***$3', $sql);
        $sql = (string) preg_replace('/(ATTACH\s+\'host=.*?\bpassword\s*=\s*).*?(?=\s+dbname\s*=)/is', '$1***', $sql);
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
