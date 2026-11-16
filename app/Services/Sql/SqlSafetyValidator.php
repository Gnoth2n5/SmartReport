<?php

namespace App\Services\Sql;

use App\Exceptions\SqlSafetyException;

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
     * Alias for validate().
     *
     * @throws SqlSafetyException
     */
    public function assertSafe(string $sql): void
    {
        $this->validate($sql);
    }

    /**
     * Check if a SQL string is safe to execute.
     *
     * @param  list<string>  $violations
     */
    public function isSafe(string $sql, array &$violations = []): bool
    {
        $violations = [];
        $trimmed = trim($sql);

        if ($trimmed === '') {
            $violations[] = 'SQL query cannot be empty.';

            return false;
        }

        // 1. Strip comments and string literals for syntax and keyword inspection
        $withoutComments = $this->stripComments($trimmed);
        $normalized = trim($withoutComments);

        if ($normalized === '') {
            $violations[] = 'SQL query cannot consist solely of comments.';

            return false;
        }

        // 2. Reject multiple statements (semicolons followed by non-whitespace statements)
        $withoutLiterals = $this->stripStringLiterals($withoutComments);
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

        // 5. Block filesystem and dangerous functions (read_csv, read_parquet, write_*, etc.)
        foreach (self::FORBIDDEN_FUNCTIONS as $fn) {
            $pattern = '/\b'.preg_quote($fn, '/').'\b/i';
            if (preg_match($pattern, $withoutLiterals)) {
                $violations[] = "Forbidden SQL function detected: [{$fn}].";

                return false;
            }
        }

        // 6. Block any write_* filesystem functions
        if (preg_match('/\bwrite_[a-zA-Z0-9_]+\b/i', $withoutLiterals, $m)) {
            $violations[] = "Forbidden filesystem write function detected: [{$m[0]}].";

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

        // Split by semicolon
        $parts = explode(';', $sqlWithoutLiterals);
        $nonEmpty = [];
        foreach ($parts as $part) {
            if (trim($part) !== '') {
                $nonEmpty[] = $part;
            }
        }

        return count($nonEmpty) > 1;
    }

    /**
     * Strip SQL single-line and multiline comments.
     */
    public function stripComments(string $sql): string
    {
        // Remove single-line comments (-- ...)
        $noSingle = preg_replace('/--.*$/m', '', $sql);

        // Remove multiline comments (/* ... */)
        $noMulti = preg_replace('/\/\*.*?\*\//s', '', (string) $noSingle);

        return (string) $noMulti;
    }

    /**
     * Strip string literals ('...') to prevent false positives on keywords inside values.
     */
    public function stripStringLiterals(string $sql): string
    {
        // Replace single-quoted strings: '([^'\\]|\\.)*'
        return (string) preg_replace("/'([^'\\\\]|\\\\.)*'/", "''", $sql);
    }
}
