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
        'parquet_scan',
        'read_duckdb',
        'glob',
        'duckdb_secrets',
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
        bool &$hasUnclosedComment = false
    ): string {
        $len = strlen($sql);
        $out = '';
        $i = 0;
        $hasUnclosedLiteral = false;
        $hasUnclosedComment = false;

        while ($i < $len) {
            $c = $sql[$i];

            // 1. Single-quoted string literal
            if ($c === "'") {
                $start = $i;
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === "'") {
                        // Escaped single quote '' in standard SQL
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $i += 2;

                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    } elseif ($sql[$i] === '\\' && $i + 1 < $len) {
                        // Backslash escape \'
                        $i += 2;

                        continue;
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

            // 2. Single-line comment (-- ...)
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
            $out .= $c;
            $i++;
        }

        return $out;
    }
}
