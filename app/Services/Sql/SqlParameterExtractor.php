<?php

namespace App\Services\Sql;

use App\Models\CustomView;

class SqlParameterExtractor
{
    /**
     * Regex pattern to identify parameter tokens starting with @.
     */
    public const PARAM_REGEX = '/@([a-zA-Z0-9_]+)/';

    protected SqlSafetyValidator $validator;

    public function __construct(?SqlSafetyValidator $validator = null)
    {
        $this->validator = $validator ?? new SqlSafetyValidator;
    }

    /**
     * Extract parameter definitions from a raw SQL string.
     *
     * @return list<array{name: string, label: string, type: string, default_value: mixed, options: array<string, string>|null, is_required: bool}>
     */
    public function extract(string $sql): array
    {
        $tokens = $this->extractTokens($sql);
        $parameters = [];

        foreach ($tokens as $token) {
            $parameters[] = $this->inferParameterDefinition($token);
        }

        return $parameters;
    }

    /**
     * Extract unique parameter token names from a SQL string.
     *
     * @return list<string>
     */
    public function extractTokens(string $sql): array
    {
        if (trim($sql) === '') {
            return [];
        }

        // Mask comments and string literals so parameter-like tokens inside them (e.g. emails) are ignored
        $sanitized = $this->validator->stripCommentsAndLiterals($sql);

        if (preg_match_all(self::PARAM_REGEX, $sanitized, $matches)) {
            $rawTokens = $matches[1];
            $unique = [];
            foreach ($rawTokens as $token) {
                $trimmed = trim($token);
                if ($trimmed !== '' && ! in_array($trimmed, $unique, true)) {
                    $unique[] = $trimmed;
                }
            }

            return array_values($unique);
        }

        return [];
    }

    /**
     * Extract parameters from a CustomView instance (both sql_text and source_queries).
     *
     * @return list<array{name: string, label: string, type: string, default_value: mixed, options: array<string, string>|null, is_required: bool}>
     */
    public function extractFromCustomView(CustomView $view): array
    {
        $sqls = [];

        if (! empty($view->sql_text)) {
            $sqls[] = $view->sql_text;
        }

        if (is_array($view->source_queries)) {
            foreach ($view->source_queries as $sourceQuery) {
                if (is_array($sourceQuery) && ! empty($sourceQuery['sql_text'])) {
                    $sqls[] = $sourceQuery['sql_text'];
                }
            }
        }

        $allTokens = [];
        foreach ($sqls as $sql) {
            foreach ($this->extractTokens($sql) as $token) {
                if (! in_array($token, $allTokens, true)) {
                    $allTokens[] = $token;
                }
            }
        }

        $parameters = [];
        foreach ($allTokens as $token) {
            $parameters[] = $this->inferParameterDefinition($token);
        }

        return $parameters;
    }

    /**
     * Infer input type, default value, label, and options for a parameter token.
     *
     * @return array{name: string, label: string, type: string, default_value: mixed, options: array<string, string>|null, is_required: bool}
     */
    public function inferParameterDefinition(string $token): array
    {
        $name = ltrim($token, '@');
        $lower = strtolower($name);

        $type = 'text';
        $defaultValue = null;
        $options = null;

        // 1. @today -> Date input, default date('now')
        if ($lower === 'today') {
            $type = 'date';
            $defaultValue = date('Y-m-d');
        }
        // 2. Matches .*Date.* or .*Time.* -> Date input
        elseif (preg_match('/(Date|Time)/i', $name)) {
            $type = 'date';
        }
        // 3. Matches .*Qty.*, .*Amount.*, .*Price.*, .*Cost.*, .*Num.* -> Number input
        elseif (preg_match('/(Qty|Amount|Price|Cost|Num)/i', $name)) {
            $type = 'number';
        }
        // 4. Matches .*Plant.*, .*Dept.*, .*Status.* -> Select input with suggested options
        elseif (preg_match('/(Plant|Dept|Status)/i', $name)) {
            $type = 'select';
            $options = $this->suggestOptionsForParameter($name);
        }
        // 5. Default -> Text input
        else {
            $type = 'text';
        }

        $label = $this->generateLabel($name);

        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'default_value' => $defaultValue,
            'options' => $options,
            'is_required' => false,
        ];
    }

    /**
     * Suggest dropdown options for common enterprise parameters.
     *
     * @return array<string, string>
     */
    protected function suggestOptionsForParameter(string $paramName): array
    {
        if (preg_match('/Plant/i', $paramName)) {
            return [
                'NORTH' => 'Miền Bắc',
                'SOUTH' => 'Miền Nam',
            ];
        }

        if (preg_match('/Dept/i', $paramName)) {
            return [
                'PRD' => 'Sản xuất & May mặc',
                'QA' => 'Quản lý chất lượng',
                'TECH' => 'Kỹ thuật bảo trì',
                'LOG' => 'Logistics & Kho vận',
                'EXEC' => 'Ban giám đốc',
            ];
        }

        if (preg_match('/Status/i', $paramName)) {
            return [
                'ACTIVE' => 'Hoạt động',
                'IN_PROGRESS' => 'Đang thực hiện',
                'COMPLETED' => 'Hoàn tất',
                'PENDING' => 'Chờ duyệt',
                'CANCELLED' => 'Đã hủy',
            ];
        }

        return [];
    }

    /**
     * Generate human-readable label from parameter name.
     */
    protected function generateLabel(string $paramName): string
    {
        $lower = strtolower($paramName);

        if ($lower === 'today') {
            return 'Hôm nay';
        }
        if ($lower === 'startdate') {
            return 'Từ ngày';
        }
        if ($lower === 'enddate') {
            return 'Đến ngày';
        }
        if (str_contains($lower, 'status')) {
            return 'Trạng thái';
        }
        if (str_contains($lower, 'plant')) {
            return 'Nhà máy';
        }
        if (str_contains($lower, 'dept')) {
            return 'Phòng ban';
        }
        if (str_contains($lower, 'amount')) {
            return 'Số tiền';
        }
        if (str_contains($lower, 'qty')) {
            return 'Số lượng';
        }

        // CamelCase / PascalCase to Title Case
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', $paramName);

        return ucwords(trim((string) $spaced));
    }
}
