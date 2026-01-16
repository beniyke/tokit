<?php

declare(strict_types=1);

/**
 * Anchor Framework
 *
 * Tokit — The Ultimate Token Saver + Data Explorer
 *
 * @author BenIyke <beniyke34@gmail.com> | Twitter: @BigBeniyke
 */

namespace Tokit;

use InvalidArgumentException;
use RuntimeException;

final class Tokit
{
    // Most common JSON schema keys → 1-character shortcuts
    private const COMMON_KEYS = [
        'name' => 'a',
        'type' => 'b',
        'description' => 'c',
        'properties' => 'd',
        'required' => 'e',
        'items' => 'f',
        'enum' => 'g',
        'parameters' => 'h',
        'function' => 'i',
        'functions' => 'j',
        'content' => 'k',
        'role' => 'l',
        'messages' => 'm',
        'model' => 'n',
        'temperature' => 'o',
        'tools' => 'p',
        'tool_choice' => 'q',
        'response_format' => 'r',
        'title' => 't',
        'example' => 'u',
        'default' => 'v',
        'additionalProperties' => 'w',
        'pattern' => 'x',
        'minimum' => 'y',
        'maximum' => 'z',
        'format' => 'A',
        'anyOf' => 'B',
        'allOf' => 'C',
        'oneOf' => 'D',
    ];

    private const MAX_DEPTH = 100;
    private const MAX_INPUT_SIZE = 10_000_000; // 10MB

    private static array $keyMap = self::COMMON_KEYS;

    private static array $reverseMap = [];

    private static int $nextKeyId = 0;

    private static array $previewConfig = [
        'enabled' => true,
        'escape_style' => 'html', // default escape mode
        'escape' => [], // per-column: ['email' => 'html_attr']
        'search' => null, // advanced + fuzzy search query
        'filter' => null,
        'sort' => null,
        'page' => 1,
        'per_page' => 50,
        'export_csv' => false,
        'csv_filename' => 'tokit-export.csv',
        'show_header' => true,
        'show_footer' => true,
        'truncate' => 150,
    ];

    public static function compress(array|object $data): string
    {
        self::resetKeyMap();
        $body = self::encodeValue($data);

        $customKeys = array_diff_key(self::$keyMap, self::COMMON_KEYS);
        $header = $customKeys
            ? 'K{' . implode(',', array_map(
                fn ($long, $short) => $short . ':' . str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $long),
                array_keys($customKeys),
                $customKeys
            )) . '}K'
            : '';

        return $header . $body;
    }

    public static function decompress(string $input): array
    {
        if (strlen($input) > self::MAX_INPUT_SIZE) {
            throw new InvalidArgumentException('Input exceeds maximum size limit');
        }

        if (! preg_match('/^(K\{[^}]*\}K)?[\[\{]/', $input)) {
            throw new InvalidArgumentException('Invalid Tokit format');
        }

        self::resetKeyMap();

        if (str_starts_with($input, 'K{') && ($end = strpos($input, '}K', 2)) !== false) {
            foreach (explode(',', substr($input, 2, $end - 2)) as $pair) {
                [$short, $long] = explode(':', $pair, 2);
                $long = str_replace(['\\:', '\\\\'], [':', '\\'], $long);
                self::$keyMap[$long] = $short;
                self::$reverseMap[$short] = $long;
            }
            $input = substr($input, $end + 2);
        }

        return self::decodeValue($input);
    }

    public static function tokenSavings(array|object $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $originalTokens = (int) ceil(strlen($json) / 3.85);
        $compressedTokens = (int) ceil(strlen(self::compress($data)) / 3.85);
        $savedPercent = $originalTokens > 0 ? round(100 - ($compressedTokens / $originalTokens * 100), 1) : 0;

        return "$originalTokens → $compressedTokens tokens (saved $savedPercent%)";
    }

    public static function preview(array|object $data, ?array $config = null): string
    {
        $config = array_replace_recursive(self::$previewConfig, $config ?? []);
        if (! $config['enabled']) {
            return self::compress($data);
        }

        $compressed = self::compress($data);
        $rows = self::decompress($compressed);

        if (! is_array($rows) || empty($rows) || ! is_array(reset($rows))) {
            return self::renderFallback($rows, $compressed, $config);
        }

        $results = [];

        // Advanced search with relevance scoring
        if ($query = trim($config['search'] ?? '')) {
            $ranker = self::createRanker($query);
            foreach ($rows as $index => $row) {
                if ($score = $ranker($row)) {
                    $results[] = ['row' => $row, 'score' => $score, 'original_index' => $index];
                }
            }
            usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
        } else {
            $results = array_map(fn ($row, $i) => ['row' => $row, 'score' => 1.0, 'original_index' => $i], $rows, array_keys($rows));
        }

        $totalRows = count($results);
        $page = max(1, (int) ($config['page'] ?? 1));
        $perPage = max(1, min(1000, (int) ($config['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($results, $offset, $perPage);

        if ($config['export_csv']) {
            return self::exportAsCsv(array_column($results, 'row'), $config['csv_filename']);
        }

        return self::renderTable($pageRows, $totalRows, $page, $perPage, $config, $compressed, $data);
    }

    private static function createRanker(string $query): callable
    {
        $query = trim($query);
        if ($query === '') {
            return fn () => 1.0;
        }

        $orGroups = array_filter(array_map('trim', preg_split('/\s+OR\s+/i', $query)));
        $groupScorers = [];

        foreach ($orGroups as $group) {
            $terms = preg_split('/\s+/', $group);
            $termScorers = [];

            foreach ($terms as $term) {
                $term = trim($term);
                if ($term === '') {
                    continue;
                }

                $negate = str_starts_with($term, '-');
                if ($negate) {
                    $term = substr($term, 1);
                }

                $isFuzzy = str_starts_with($term, '~');
                if ($isFuzzy) {
                    $term = substr($term, 1);
                }

                [$field, $value] = str_contains($term, ':')
                    ? explode(':', $term, 2)
                    : ['_all', $term];

                $value = trim($value, '"');
                $isExact = str_starts_with($value, '"') && str_ends_with($value, '"');
                if ($isExact) {
                    $value = trim($value, '"');
                }

                $termScorers[] = function ($row) use ($field, $value, $isFuzzy, $isExact, $negate) {
                    $haystack = $field === '_all' ? serialize($row) : ($row[$field] ?? '');
                    $haystack = strtolower((string) $haystack);
                    $needle = strtolower($value);

                    if ($isExact) {
                        $match = str_contains($haystack, '"' . $needle . '"');

                        return $negate !== $match ? 1.0 : 0.0;
                    }

                    if ($isFuzzy) {
                        $score = self::calculateFuzzyScore($needle, $haystack);

                        return $score > 0.7 ? ($negate ? 0.0 : $score) : 0.0;
                    }

                    if (str_contains($value, '*') || str_contains($value, '?')) {
                        $pattern = '#^' . strtr(preg_quote($value, '#'), ['\*' => '.*', '\?' => '.']) . '$#i';
                        $match = preg_match($pattern, $haystack);

                        return $negate !== $match ? 0.9 : 0.0;
                    }

                    $match = str_contains($haystack, $needle);

                    return $negate !== $match ? 0.85 : 0.0;
                };
            }

            $groupScorers[] = $termScorers;
        }

        return function ($row) use ($groupScorers) {
            $bestScore = 0.0;
            foreach ($groupScorers as $scorers) {
                $groupScore = 1.0;
                foreach ($scorers as $scorer) {
                    $groupScore = min($groupScore, $scorer($row));
                    if ($groupScore === 0.0) {
                        break;
                    }
                }
                $bestScore = max($bestScore, $groupScore);
            }

            return $bestScore > 0.0 ? $bestScore : 0.0;
        };
    }

    private static function calculateFuzzyScore(string $needle, string $haystack): float
    {
        $needle = trim($needle);
        $haystack = trim($haystack);
        if ($needle === '' || $haystack === '') {
            return 0.0;
        }

        if ($needle === $haystack) {
            return 1.0;
        }

        similar_text($needle, $haystack, $similarity);
        $distance = levenshtein($needle, $haystack);
        $maxLength = max(strlen($needle), strlen($haystack));

        $levenshteinScore = $distance < 4 ? (1.0 - $distance / $maxLength) : 0.0;

        return max($similarity / 100, $levenshteinScore);
    }

    private static function renderTable(
        array $pageRows,
        int $totalRows,
        int $currentPage,
        int $perPage,
        array $config,
        string $compressed,
        $originalData
    ): string {
        $rows = array_column($pageRows, 'row');
        $columns = $config['columns'] ?? array_keys((array) reset($rows));
        $columnEscape = $config['escape'] ?? [];

        $html = '<div class="tokit-preview"><style>
            .tokit-preview table {font-family: ui-monospace, Menlo, monospace; font-size: 13px; border-collapse: collapse; width: 100%; max-width: 1200px; margin: 16px 0;}
            .tokit-preview th, .tokit-preview td {padding: 10px 14px; border: 1px solid #e1e5e9; text-align: left; max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;}
            .tokit-preview th {background: #f8f9fa; font-weight: 600; position: sticky; top: 0; z-index: 10;}
            .tokit-preview tr:nth-child(even) {background: #fdfdfd;}
            .tokit-preview .footer {margin-top: 12px; font-size: 11px; color: #666; font-family: system-ui;}
            .tokit-preview .pagination {margin-top: 12px; font-size: 12px;}
            .tokit-preview .pagination a {margin: 0 4px; padding: 6px 10px; background: #f0f0f0; border-radius: 4px; text-decoration: none; color: #333;}
            .tokit-preview .pagination a.current {background: #007bff; color: white;}
        </style><table>';

        if ($config['show_header'] ?? true) {
            $html .= '<tr><th>#</th>';
            if ($config['search']) {
                $html .= '<th>Relevance</th>';
            }

            foreach ($columns as $col) {
                $label = ($config['column_label'] ?? null) ? ($config['column_label'])($col) : $col;
                $html .= '<th>' . self::escape($label, $columnEscape[$col] ?? $config['escape_style'] ?? 'html') . '</th>';
            }
            $html .= '</tr>';
        }

        foreach ($pageRows as $index => $item) {
            $row = $item['row'];
            $globalIndex = ($currentPage - 1) * $perPage + $index + 1;

            $html .= '<tr><td>' . $globalIndex . '</td>';
            if ($config['search']) {
                $score = $item['score'] ?? 0;
                $opacity = 0.6 + ($score * 0.4);
                $html .= '<td style="opacity:' . $opacity . '; font-size:11px;">' . round($score * 100) . '%</td>';
            }

            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $display = is_scalar($value)
                    ? substr((string) $value, 0, $config['truncate'])
                    : json_encode($value, JSON_UNESCAPED_UNICODE);

                $escapeMode = $columnEscape[$col] ?? $config['escape_style'] ?? 'html';
                $html .= '<td>' . self::escape($display, $escapeMode) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';

        if ($totalRows > $perPage) {
            $totalPages = (int) ceil($totalRows / $perPage);
            $html .= '<div class="pagination">Page ' . $currentPage . ' of ' . $totalPages . ' (' . $totalRows . ' rows) · ';

            // Whitelist allowed parameters
            $allowedParams = ['search', 'sort', 'filter', 'page', 'per_page'];
            $safeGet = array_intersect_key($_GET ?? [], array_flip($allowedParams));

            for ($p = 1; $p <= $totalPages; $p++) {
                $query = http_build_query(array_merge($safeGet, ['page' => $p]));
                $html .= $p === $currentPage
                    ? "<a class=\"current\">$p</a>"
                    : "<a href=\"?$query\">$p</a>";
            }
            $csvQuery = http_build_query(array_merge($safeGet, ['export_csv' => '1']));
            $html .= ' · <a href="?' . $csvQuery . '">Export CSV</a></div>';
        }

        $html .= '<div class="footer">Tokit compressed: ' . strlen($compressed) . ' chars · ' . self::tokenSavings($originalData) . '</div></div>';

        return $html;
    }

    private static function renderFallback($data, string $compressed, array $config): string
    {
        $escaped = self::escape(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $config['escape_style'] ?? 'html');

        return '<pre style="background:#f8f8f8;padding:16px;border:1px solid #eee;font-family:ui-monospace">' .
            $escaped . '</pre><small>' . self::tokenSavings($data) . '</small>';
    }

    private static function exportAsCsv(array $rows, string $filename): string
    {
        // Sanitize filename to prevent header injection
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', basename($filename));
        if ($filename === '') {
            $filename = 'tokit-export';
        }
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'w');

        if (! empty($rows)) {
            // Sanitize CSV values to prevent formula injection
            $sanitizeCell = function ($val) {
                if (is_string($val) && preg_match('/^[=+@-]/', $val)) {
                    return "'" . $val; // Prefix with ' to prevent formula execution
                }

                return $val;
            };

            fputcsv($output, array_keys((array) reset($rows)));
            foreach ($rows as $row) {
                fputcsv($output, array_map($sanitizeCell, array_values((array) $row)));
            }
        }
        exit;
    }

    private static function escape(mixed $value, string|callable $style): string
    {
        if (is_string($style) && in_array($style, ['html', 'html_attr', 'js', 'url', 'none'], true)) {
            return match ($style) {
                'html' => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'html_attr' => htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'js' => addslashes((string) $value),
                'url' => rawurlencode((string) $value),
                'none' => (string) $value,
            };
        }

        if (is_callable($style)) {
            return (string) $style($value);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function resetKeyMap(): void
    {
        self::$keyMap = self::COMMON_KEYS;
        self::$reverseMap = array_flip(self::COMMON_KEYS);
        self::$nextKeyId = count(self::COMMON_KEYS);
    }

    private static function encodeValue(mixed $value, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Maximum nesting depth exceeded');
        }

        return match (true) {
            $value === null => 'n',
            $value === true => 't',
            $value === false => 'f',
            is_string($value) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"',
            is_numeric($value) => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            default => self::encodeContainer((array) $value, $depth),
        };
    }

    private static function encodeContainer(array $array, int $depth = 0): string
    {
        $isSequential = array_is_list($array);
        $parts = [];

        foreach ($array as $key => $value) {
            $encodedKey = $isSequential ? '' : self::shortenKey((string) $key) . ':';
            $parts[] = $encodedKey . self::encodeValue($value, $depth + 1);
        }

        return $isSequential ? '[' . implode(',', $parts) . ']' : '{' . implode(',', $parts) . '}';
    }

    private static function shortenKey(string $longKey): string
    {
        if (isset(self::$keyMap[$longKey])) {
            return self::$keyMap[$longKey];
        }

        do {
            $short = base_convert((string) self::$nextKeyId++, 10, 36);
        } while (isset(self::$reverseMap[$short]));

        self::$keyMap[$longKey] = $short;
        self::$reverseMap[$short] = $longKey;

        return $short;
    }

    private static function decodeValue(string $input): mixed
    {
        $input = trim($input);

        return match ($input[0] ?? '') {
            'n' => null,
            't' => true,
            'f' => false,
            '"' => str_replace(['\\"', '\\\\'], ['"', '\\'], substr($input, 1, -1)),
            '[', '{' => self::decodeContainer($input),
            default => str_contains($input, '.') ? (float) $input : (int) $input,
        };
    }

    private static function decodeContainer(string $input): array
    {
        $isObject = $input[0] === '{';
        $result = [];
        $length = strlen($input);
        $depth = 0;
        $inString = false;
        $start = 1;

        for ($i = 1; $i < $length - 1; $i++) {
            if ($input[$i] === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($input[$i] === '"') {
                $inString = ! $inString;
            }

            if ($inString) {
                continue;
            }

            if ($input[$i] === '[' || $input[$i] === '{') {
                $depth++;
            }

            if ($input[$i] === ']' || $input[$i] === '}') {
                $depth--;
            }

            if ($input[$i] === ',' && $depth === 0) {
                $part = substr($input, $start, $i - $start);
                $result[] = $isObject ? self::decodeKeyValuePair($part) : self::decodeValue($part);
                $start = $i + 1;
            }
        }

        $lastPart = substr($input, $start, $length - $start - 1); // Exclude closing brace/bracket
        if ($lastPart !== '') {
            $result[] = $isObject ? self::decodeKeyValuePair($lastPart) : self::decodeValue($lastPart);
        }

        return $isObject ? self::expandKeys($result) : $result;
    }

    private static function decodeKeyValuePair(string $pair): array
    {
        [$key, $value] = explode(':', $pair, 2);
        $longKey = self::$reverseMap[$key] ?? $key;

        return [$longKey => self::decodeValue($value)];
    }

    private static function expandKeys(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
