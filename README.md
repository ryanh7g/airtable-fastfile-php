# Airtable FastFlat PHP

A fast PHP client for the Airtable API with flat file caching and attachment support.

## Features

- 🚀 **Full Airtable API Support**: Create, read, update, and delete records
- 💾 **Intelligent Caching**: Built-in response caching to improve performance
- 📎 **Attachment Handling**: Automatic caching and local storage of image attachments
- 🛡️ **Error Handling**: Comprehensive error handling with meaningful exceptions
- 📋 **PSR-4 Compatible**: Modern PHP standards with proper namespacing
- 🔧 **Type Safe**: Full PHP type declarations for better IDE support

## Installation

Install via Composer:

```bash
composer require ryanh7g/airtable-fastflat-php
```

## Requirements

- PHP 7.4 or higher
- cURL extension
- JSON extension

## Usage

### Basic Setup

```php
<?php
require_once 'vendor/autoload.php';

use BetterAirtable\Airtable;

// With caching enabled
$airtable = new Airtable(
    'your-api-key',           // Airtable API key
    'your-base-id',           // Airtable base ID
    'your-table-name',        // Table name
    './cache',                // Cache directory (optional)
    './cache/files'           // File cache directory (optional)
);

// Without caching (pass null for cache directories)
$airtable = new Airtable(
    'your-api-key',           // Airtable API key
    'your-base-id',           // Airtable base ID
    'your-table-name'         // Table name
    // No cache directories = no caching
);
```

### Get All Records

```php
try {
    // Get all records
    $response = $airtable->getRecords();
    
    // Get records with view filter
    $response = $airtable->getRecords('My View');
    
    // Get records with formula filter
    $response = $airtable->getRecords('', '{Status} = "Active"');
    
    // Get records ignoring cache
    $response = $airtable->getRecords('', '', true);
    
    foreach ($response['records'] as $record) {
        echo "Record ID: " . $record['id'] . "\n";
        print_r($record['fields']);
    }
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Get Single Record

```php
try {
    $record = $airtable->getRecord('recXXXXXXXXXXXXXX');
    echo "Record: " . $record['fields']['Name'] . "\n";
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Add Record

```php
try {
    $newRecord = $airtable->addRecord([
        'Name' => 'John Doe',
        'Email' => 'john@example.com',
        'Status' => 'Active'
    ]);
    
    echo "Created record: " . $newRecord['id'] . "\n";
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Update Record

```php
try {
    $updatedRecord = $airtable->updateRecord('recXXXXXXXXXXXXXX', [
        'Status' => 'Inactive',
        'Notes' => 'Updated via API'
    ]);
    
    echo "Updated record: " . $updatedRecord['records'][0]['id'] . "\n";
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Delete Record

```php
try {
    $result = $airtable->deleteRecord('recXXXXXXXXXXXXXX');
    echo "Deleted record: " . $result['records'][0]['deleted'] ? 'Success' : 'Failed' . "\n";
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Caching

The library provides optional caching to improve performance:

- **Conditional Caching**: Only enabled when cache directories are provided to the constructor
- **Response Caching**: API responses are cached as JSON files (when cache directory is set)
- **Attachment Caching**: Image attachments are downloaded and cached locally (when file cache directory is set)
- **Smart Invalidation**: Cache is automatically cleared when records are updated or deleted
- **Cache Control**: Use the `$ignoreCache` parameter to bypass cache when needed

### Cache Behavior

- **No Cache Directories**: If you pass `null` (or omit) cache directories, no caching occurs
- **GET Requests**: Cached when cache directory is provided
- **Add Record**: Does not invalidate existing cache (new record will be cached when first requested)
- **Update Record**: Automatically invalidates all cache to ensure data consistency
- **Delete Record**: Automatically invalidates all cache to ensure data consistency

### Cache Directory Structure

```
cache/                   # Response cache directory (optional)
├── [hash].json          # Cached API responses
└── files/               # File cache directory (optional)
    ├── attXXXXXX.jpg    # Cached image attachments
    └── attYYYYYY.png
```

## Attachment Handling

When records contain image attachments, the library automatically:

1. Downloads the attachment files
2. Caches them locally with proper file extensions
3. Adds a `cached_url` field to the attachment data

```php
$records = $airtable->getRecords();
foreach ($records['records'] as $record) {
    if (isset($record['fields']['Photo'])) {
        foreach ($record['fields']['Photo'] as $attachment) {
            if (isset($attachment['cached_url'])) {
                echo "Local file: " . $attachment['cached_url'] . "\n";
            }
        }
    }
}
```

## Error Handling

The library throws `RuntimeException` for various error conditions:

- cURL failures
- HTTP errors (4xx, 5xx responses)
- File system errors
- Invalid API responses

Always wrap API calls in try-catch blocks for proper error handling.

## API Reference

### Constructor

```php
public function __construct(
    string $api_key,              // Required: Airtable API key
    string $base_id,              // Required: Airtable base ID 
    string $table_name,           // Required: Table name
    ?string $cache_dir = null,    // Optional: Response cache directory (null = no caching)
    ?string $file_cache_dir = null // Optional: File cache directory (null = no file caching)
)
```

### Methods

| Method | Description | Parameters | Returns |
|--------|-------------|------------|---------|
| `getRecords()` | Get records from table | `$view`, `$filterByFormula`, `$ignoreCache` | `array\|null` |
| `getRecord()` | Get single record | `$recordId`, `$ignoreCache` | `array\|null` |
| `addRecord()` | Create new record | `$data` | `array\|null` |
| `updateRecord()` | Update existing record | `$recordId`, `$data` | `array\|null` |
| `deleteRecord()` | Delete record | `$recordId` | `array\|null` |


## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

If you encounter any issues or have questions, please [open an issue](https://github.com/ryanh7g/airtable-fastflat-php/issues) on GitHub.
