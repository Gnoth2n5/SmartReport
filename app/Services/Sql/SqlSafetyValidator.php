<?php

namespace App\Services\Sql;

use App\Exceptions\SqlSafetyException;
use Closure;

class SqlSafetyValidator
{
    /**
     * Forbidden DDL, DML, filesystem, and system execution keywords.
     *
     * @var list<string>
     */
    protected const FORBIDDEN_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'REPLACE',
        'GRANT',
        'REVOKE',
        'EXEC',
        'EXECUTE',
        'CALL',
        'PRAGMA',
        'VACUUM',
        'CHECKPOINT',
        'COPY',
        'EXPORT',
        'IMPORT',
        'ATTACH',
        'DETACH',
        'INSTALL',
        'LOAD',
    ];

    /**
     * Forbidden function prefixes or specific dangerous functions.
     *
     * @var list<string>
     */
    protected const FORBIDDEN_FUNCTIONS = [
        'read_csv',
        'read_csv_auto',
        'read_parquet',
        'read_json',
        'read_json_auto',
        'read_blob',
        'read_text',
        'read_ndjson',
        'read_xlsx',
        'parquet_scan',
        'csv_scan',
        'json_scan',
        'xlsx_scan',
        'sqlite_scan',
        'iceberg_scan',
        'delta_scan',
        'mssql_scan',
        'read_duckdb',
        'glob',
        'duckdb_secrets',
        'sniff_csv',
        'getenv',
        'current_setting',
        'postgres_query',
        'postgres_execute',
        'oracle_query',
    ];

    /**
     * Validate the given SQL string. Throws SqlSafetyException if any violation is found.
     *
     * @throws SqlSafetyException
     */
    public function validate(string $sql): void
    {
        $violations = [];
        if (! $this->isSafe($sql, $violations)) {
            throw new SqlSafetyException(implode(' ', $violations));
        }
    }

    /**
     * Check if a SQL string is safe to execute.
     *
     * @param  list<string>|null  $violations
     */
    public function isSafe(string $sql, ?array &$violations = null): bool
    {
        $violations = [];
        $trimmed = trim($sql);

        if ($trimmed === '') {
            $violations[] = 'SQL query cannot be empty.';

            return false;
        }

        $hasUnclosedLiteral = false;
        $hasUnclosedComment = false;

        // 1. Strip comments in token order (preserving string literals and comment delimiters within them)
        $withoutComments = $this->tokenizeAndSanitize(
            $trimmed,
            stripComments: true,
            stripLiterals: false,
            hasUnclosedLiteral: $hasUnclosedLiteral,
            hasUnclosedComment: $hasUnclosedComment
        );

        if ($hasUnclosedLiteral) {
            $violations[] = 'Unclosed string literal detected in SQL query.';

            return false;
        }

        if ($hasUnclosedComment) {
            $violations[] = 'Unclosed multiline comment detected in SQL query.';

            return false;
        }

        $normalized = trim($withoutComments);

        if ($normalized === '') {
            $violations[] = 'SQL query cannot consist solely of comments.';

            return false;
        }

        // 2. Reject multiple statements (semicolons followed by non-whitespace statements)
        $withoutLiterals = $this->stripStringLiterals($withoutComments);
        if (preg_match("/\b(?:FROM|JOIN)\s*(?:\(\s*)*''/i", $withoutLiterals)) {
            $violations[] = 'File paths cannot be used as SQL table sources.';

            return false;
        }
        if ($this->hasMultipleStatements($withoutLiterals)) {
            $violations[] = 'Multiple SQL statements separated by semicolons are strictly prohibited.';

            return false;
        }

        // 3. Only allow read statements (SELECT / WITH)
        if (! preg_match('/^(SELECT|WITH)\b/i', $normalized)) {
            $violations[] = 'Only read queries starting with SELECT or WITH are permitted.';

            return false;
        }

        // 4. Block forbidden DDL, DML, system, and DuckDB catalog/extension keywords
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            $pattern = '/\b'.preg_quote($keyword, '/').'\b/i';
            if (preg_match($pattern, $withoutLiterals)) {
                $violations[] = "Forbidden SQL keyword detected: [{$keyword}].";

                return false;
            }
        }

        // 5. Block filesystem and dangerous functions (read_csv, read_parquet, parquet_scan, glob, duckdb_secrets, etc.)
        if (preg_match('/\b(?:(?:mssql|postgres|oracle)_[a-zA-Z0-9_]+|query(?:_table)?)\b\s*"?\s*\(/i', $withoutLiterals)) {
            $violations[] = 'Native passthrough and dynamic SQL functions are forbidden.';

            return false;
        }

        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            $pattern = '/\b'.preg_quote($fn, '/').'\b/i';
            if (preg_match($pattern, $withoutLiterals)) {
                $violations[] = "Forbidden SQL function detected: [{$fn}].";

                return false;
            }
        }

        // 6. Block any read_* table functions (e.g. read_json_objects, read_single_csv_file, read_ndjson_auto, etc.)
        if (preg_match('/\bread_[a-zA-Z0-9_]+\b/i', $withoutLiterals, $m)) {
            $violations[] = "Forbidden SQL function detected: [{$m[0]}].";

            return false;
        }

        // 7. Block any write_* filesystem functions
        if (preg_match('/\bwrite_[a-zA-Z0-9_]+\b/i', $withoutLiterals, $m)) {
            $violations[] = "Forbidden filesystem write function detected: [{$m[0]}].";

            return false;
        }

        // 8. Block DuckDB catalog/secret/pragma/sniff functions (duckdb_*, pragma_*, sniff_*)
        if (preg_match('/\b(?:duckdb|pragma|sniff)_[a-zA-Z0-9_]+\b/i', $withoutLiterals, $m)) {
            $violations[] = "Forbidden SQL function detected: [{$m[0]}].";

            return false;
        }

        // 9. Block any *_scan table functions (csv_scan, json_scan, xlsx_scan, sqlite_scan, iceberg_scan, delta_scan, mssql_scan, etc.)
        if (preg_match('/\b[a-zA-Z0-9_]+_scan\b/i', $withoutLiterals, $m)) {
            $violations[] = "Forbidden SQL function detected: [{$m[0]}].";

            return false;
        }

        return true;
    }

    /**
     * Check if the SQL string contains multiple statements.
     */
    protected function hasMultipleStatements(string $sqlWithoutLiterals): bool
    {
        if (! str_contains($sqlWithoutLiterals, ';')) {
            return false;
        }

        // Split by semicolon only outside quoted literals/identifiers
        $len = strlen($sqlWithoutLiterals);
        $parts = [];
        $buf = '';
        $i = 0;

        while ($i < $len) {
            $c = $sqlWithoutLiterals[$i];

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $start = $i;
                $i++;
                while ($i < $len) {
                    if ($sqlWithoutLiterals[$i] === $quote) {
                        if ($i + 1 < $len && $sqlWithoutLiterals[$i + 1] === $quote) {
                            $i += 2;

                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $buf .= substr($sqlWithoutLiterals, $start, $i - $start);

                continue;
            }

            if ($c === ';') {
                $parts[] = $buf;
                $buf = '';
                $i++;

                continue;
            }

            $buf .= $c;
            $i++;
        }
        $parts[] = $buf;

        $nonEmpty = [];
        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $nonEmpty[] = $part;
            }
        }

        return count($nonEmpty) > 1;
    }

    /**
     * Strip SQL single-line and multiline comments in token order,
     * ensuring comment delimiters inside string literals are preserved.
     */
    public function stripComments(string $sql): string
    {
        return $this->tokenizeAndSanitize($sql, stripComments: true, stripLiterals: false);
    }

    /**
     * Strip string literals ('...') in token order to prevent false positives
     * on keywords inside values.
     */
    public function stripStringLiterals(string $sql): string
    {
        return $this->tokenizeAndSanitize($sql, stripComments: false, stripLiterals: true);
    }

    /**
     * Strip both comments and string literals in a single safe token-order pass.
     */
    public function stripCommentsAndLiterals(string $sql): string
    {
        return $this->tokenizeAndSanitize($sql, stripComments: true, stripLiterals: true);
    }

    /**
     * Tokenize SQL string in sequential order to safely process string literals
     * and comments without cross-token delimiter confusion.
     */
    public function tokenizeAndSanitize(
        string $sql,
        bool $stripComments = true,
        bool $stripLiterals = false,
        bool &$hasUnclosedLiteral = false,
        bool &$hasUnclosedComment = false,
        ?Closure $transformCode = null
    ): string {
        $len = strlen($sql);
        $out = '';
        $code = '';
        $i = 0;
        $hasUnclosedLiteral = false;
        $hasUnclosedComment = false;

        while ($i < $len) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"' || substr($sql, $i, 2) === '--' || substr($sql, $i, 2) === '/*'
                || ($c === '$' && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/', $sql, $tagMatch, 0, $i))) {
                $out .= $transformCode !== null ? $transformCode($code) : $code;
                $code = '';
            }

            // 1. Single-quoted string literal (ANSI SQL / DuckDB: only '' escapes ', never \')
            if ($c === "'") {
                $start = $i;
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === "'") {
                        // Escaped single quote '' in standard SQL / DuckDB
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $i += 2;

                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }

                if (! $closed) {
                    $hasUnclosedLiteral = true;
                }

                if ($stripLiterals) {
                    $out .= "''";
                } else {
                    $out .= substr($sql, $start, $i - $start);
                }

                continue;
            }

            // 2. Double-quoted SQL identifier ("..." with "" escaping)
            if ($c === '"') {
                $start = $i;
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === '"') {
                        // Escaped double quote "" in standard SQL / DuckDB
                        if ($i + 1 < $len && $sql[$i + 1] === '"') {
                            $i += 2;

                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }

                if (! $closed) {
                    $hasUnclosedLiteral = true;
                }

                // Preserve double-quoted identifiers verbatim so quoted function names (e.g. "getenv"('PATH'))
                // remain visible to keyword/function validation while preventing quote/comment desync.
                $out .= substr($sql, $start, $i - $start);

                continue;
            }

            // 3. Dollar-quoted string literal ($$...$$ or $tag$...$tag$ supported by DuckDB)
            if ($c === '$' && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/', $sql, $tagMatch, 0, $i)) {
                $tag = $tagMatch[0];
                $tagLen = strlen($tag);
                $start = $i;
                $endPos = strpos($sql, $tag, $i + $tagLen);

                if ($endPos === false) {
                    $hasUnclosedLiteral = true;
                    $i = $len;
                } else {
                    $i = $endPos + $tagLen;
                }

                if ($stripLiterals) {
                    $out .= "''";
                } else {
                    $out .= substr($sql, $start, $i - $start);
                }

                continue;
            }

            // 4. Single-line comment (-- ...)
            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $start = $i;
                $i += 2;
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                if ($stripComments) {
                    $out .= ' ';
                } else {
                    $out .= substr($sql, $start, $i - $start);
                }

                continue;
            }

            // 3. Multiline comment (/* ... */)
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $start = $i;
                $i += 2;
                $closed = false;
                while ($i + 1 < $len) {
                    if ($sql[$i] === '*' && $sql[$i + 1] === '/') {
                        $i += 2;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (! $closed) {
                    $hasUnclosedComment = true;
                    $i = $len;
                }
                if ($stripComments) {
                    $out .= ' ';
                } else {
                    $out .= substr($sql, $start, $i - $start);
                }

                continue;
            }

            // 4. Regular characters
            $code .= $c;
            $i++;
        }

        return $out.($transformCode !== null ? $transformCode($code) : $code);
    }
}
