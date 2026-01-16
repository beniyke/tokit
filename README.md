<!-- This file is auto-generated from docs/tokit.md -->

# Tokit

#### Token-Optimized Kit for LLMs

**Tokit** is a high-performance compression library designed to reduce LLM payload sizes by **75-87%**. By combining intelligent key mapping with a compact encoding format, Tokit minimizes token usage in your prompts and API responses while preserving full data integrity.

## Key Benefits

- **Significant Token Savings** - 75-87% reduction in structured payload size
- **Advanced Explorer** - Interactive HTML previews with fuzzy matching and wildcards
- **Secure by Design** - Automated XSS, CSV injection, and depth limit protection
- **Zero External Dependencies** - High-performance pure PHP implementation
- **LLM Optimized** - Designed specifically to fit more context into limited windows

## Basic Usage

### Compression & Decompression

```php
use Tokit\Tokit;

// Compress data
$data = [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'properties' => ['type' => 'user', 'active' => true]
];

$compressed = Tokit::compress($data);
// Result: K{0:email,1:active}K{a:"John Doe",0:"john@example.com",d:{b:"user",1:t}}

// Decompress
$original = Tokit::decompress($compressed);
// Returns original array
```

> Tokit uses a built-in map of 30 common keys (like `name`, `type`, `properties`) to 1-character shortcuts (a, b, d, etc.) to maximize savings before even looking at custom keys.

### Token Savings

```php
$savings = Tokit::tokenSavings($data);
// Output: "56 → 12 tokens (saved 78.6%)"
```

> Token counts are estimated using a standard factor of **3.85 characters per token**.

## Object-Oriented Usage (TokitString)

Tokit includes a `TokitString` wrapper class for easier handling of compressed data.

```php
use Tokit\TokitString;

// Create from compressed string
$compressed = new TokitString(Tokit::compress($data));

// Get token count
echo $compressed->tokens(); // e.g. 42

// Decompress back to array
$original = $compressed->decompress();

// Use as string (implements __toString)
echo "Payload: " . $compressed;
```

## HTML Preview

```php
$users = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com']
];

// Generate interactive HTML table
echo Tokit::preview($users, [
    'search' => 'alice',
    'per_page' => 50,
    'export_csv' => false,
    'truncate' => 100
]);
```

## Searching and Ranking

Tokit supports a robust search syntax with relevance scoring:

```php
// Field-specific search
'name:john'

// Fuzzy matching
'~johne'  // Matches "john", "johny", etc.

// Wildcards
'j*n'     // Matches "john", "jason", etc.

// Phrase matching
'"John Doe"'

// Negation
'-inactive'

// OR operator
'name:alice OR name:bob'

// Combined
'name:~john active:true'
```

## Configuration Options

```php
$html = Tokit::preview($data, [
    'enabled' => true,               // Enable preview (default: true)
    'search' => 'query',             // Search query
    'page' => 1,                     // Current page
    'per_page' => 50,                // Items per page (max 1000)
    'export_csv' => false,           // Export as CSV
    'csv_filename' => 'export.csv',  // CSV filename
    'truncate' => 150,               // Max string length for display
    'show_header' => true,           // Show table header
    'show_footer' => true,           // Show compression stats footer
    'escape_style' => 'html',        // Global escape mode: html, html_attr, js, url, none
    'escape' => [                    // Per-column overrides
        'raw_field' => 'none'
    ]
]);
```

## Security

Tokit includes comprehensive security measures:

### Guardrails

- **Input size limit**: 10MB (`Tokit::MAX_INPUT_SIZE`) to prevent memory exhaustion
- **Depth limit**: 100 levels (`Tokit::MAX_DEPTH`) to prevent stack overflow (DoS)
- **Format validation**: Strict protocol matching before processing

### Output Sanitization

- **XSS Protection**: HTML entity escaping
- **CSV Injection Prevention**: Formula prefixing
- **Header Injection Protection**: Filename sanitization
- **Query Parameter Whitelisting**: Only allowed parameters accepted

### Safe Usage

```php
try {
    $result = Tokit::decompress($userInput);
} catch (\InvalidArgumentException $e) {
    // Input validation failed
    log_error($e->getMessage());
} catch (\RuntimeException $e) {
    // Depth limit exceeded
    log_error($e->getMessage());
}
```

## Performance

### Benchmarks

| Data Size | Original | Compressed | Savings |
| | -- | - | - |
| 1KB | ~260 tokens | ~45 tokens | 82.7% |
| 10KB | ~2,600 tokens | ~520 tokens | 80.0% |
| 100KB | ~26,000 tokens | ~4,500 tokens | 82.7% |

### Limitations

- **Maximum input size**: 10MB
- **Maximum depth**: 100 levels
- **Best for**: Structured JSON data with repeated keys

## Advanced Features

### Custom Escape Functions

```php
$html = Tokit::preview($data, [
    'escape' => [
        'html_content' => function($value) {
            return strip_tags($value);
        }
    ]
]);
```

### CSV Export

```php
// Automatic CSV export
$csv = Tokit::preview($data, [
    'export_csv' => true,
    'csv_filename' => 'users-export'
]);
// Triggers download of "users-export.csv"
```

## Error Handling

```php
try {
    $compressed = Tokit::compress($data);
    $decompressed = Tokit::decompress($compressed);
} catch (\InvalidArgumentException $e) {
    // Input validation failed (size, format)
} catch (\RuntimeException $e) {
    // Depth limit exceeded
} catch (\Throwable $e) {
    // Unexpected error
}
```

## Best Practices

- **Validate Input**: Always validate user input before compression
- **Handle Errors**: Wrap operations in try-catch blocks
- **Limit Size**: Don't compress extremely large datasets
- **Test Round-trips**: Verify compress/decompress produces identical output
- **Monitor Usage**: Track compression ratios for optimization
