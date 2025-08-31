<?php

namespace BetterAirtable;

use InvalidArgumentException;
use RuntimeException;

/**
 * Airtable API Client with caching support
 * 
 * @package BetterAirtable
 */
class Airtable {
    private $api_key;
    private $base_id;
    private $table_name;
    private $cache_dir;
    private $file_cache_dir;

    /**
     * Constructor
     * 
     * @param string $api_key Airtable API key
     * @param string $base_id Airtable base ID
     * @param string $table_name Table name
     * @param string|null $cache_dir Cache directory path (default: ./cache)
     * @param string|null $file_cache_dir File cache directory path (default: ./cache/files)
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $api_key, 
        string $base_id, 
        string $table_name, 
        ?string $cache_dir = null, 
        ?string $file_cache_dir = null
    ) {
        if (empty($api_key)) {
            throw new InvalidArgumentException('API key cannot be empty');
        }
        if (empty($base_id)) {
            throw new InvalidArgumentException('Base ID cannot be empty');
        }
        if (empty($table_name)) {
            throw new InvalidArgumentException('Table name cannot be empty');
        }
        
        $this->api_key = $api_key;
        $this->base_id = $base_id;
        $this->table_name = $table_name;
        $this->cache_dir = $cache_dir;
        $this->file_cache_dir = $file_cache_dir;
    }

    /**
     * Check if response caching is enabled
     * 
     * @return bool
     */
    private function isCacheEnabled(): bool {
        return $this->cache_dir !== null;
    }

    /**
     * Check if file caching is enabled
     * 
     * @return bool
     */
    private function isFileCacheEnabled(): bool {
        return $this->file_cache_dir !== null;
    }

    /**
     * Get cache file path for endpoint
     * 
     * @param string $endpoint
     * @return string
     */
    private function getCacheFilePath(string $endpoint): ?string {
        if (!$this->isCacheEnabled()) {
            return null;
        }
        return "{$this->cache_dir}/" . md5($endpoint) . '.json';
    }

    /**
     * Get cached response for endpoint
     * 
     * @param string $endpoint
     * @return array|null
     */
    private function getCache(string $endpoint): ?array {
        if (!$this->isCacheEnabled()) {
            return null;
        }
        
        $filePath = $this->getCacheFilePath($endpoint);
        if ($filePath && file_exists($filePath)) {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                return json_decode($content, true) ?: null;
            }
        }
        return null;
    }

    /**
     * Cache attachments in API response data
     * 
     * @param mixed $data
     * @return mixed
     */
    private function cacheAttachments($data) {
        if (!is_array($data) || !$this->isFileCacheEnabled()) {
            return $data;
        }

        // For records endpoint
        if (isset($data['records'])) {
            foreach ($data['records'] as &$record) {
                $this->processRecordAttachments($record);
            }
        }
        // For single record endpoint
        else if (isset($data['fields'])) {
            $this->processRecordAttachments($data);
        }

        return $data;
    }

    private function processRecordAttachments(&$record) {
        if (!isset($record['fields'])) return;

        foreach ($record['fields'] as $fieldName => &$value) {
            // Check if field contains attachments (array of objects with id and url)
            if (is_array($value) && !empty($value) && isset($value[0]['id']) && isset($value[0]['url'])) {
                foreach ($value as &$attachment) {
                    if (isset($attachment['type']) && strpos($attachment['type'], 'image/') === 0) {
                        $cachedPath = $this->cacheAttachmentFile($attachment);
                        if ($cachedPath) {
                            $attachment['cached_url'] = $cachedPath;
                        }
                    }
                }
            }
        }
    }

    private function cacheAttachmentFile($attachment) {
        $imageDir = $this->file_cache_dir;
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }

        // Get extension from MIME type
        $extension = 'jpg'; // default fallback
        if (isset($attachment['type'])) {
            switch ($attachment['type']) {
                case 'image/jpeg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/gif':
                    $extension = 'gif';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
                case 'image/svg+xml':
                    $extension = 'svg';
                    break;
                case 'application/pdf':
                    $extension = 'pdf';
                    break;
                default:
                    // Try to get extension from URL if MIME type is not recognized
                    $urlExtension = pathinfo(parse_url($attachment['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
                    if ($urlExtension) {
                        $extension = strtolower($urlExtension);
                    }
            }
        }

        $cacheFile = "{$imageDir}/{$attachment['id']}.{$extension}";

        // If file is already cached, return the path
        if (file_exists($cacheFile)) {
            return $cacheFile;
        }

        // Download and cache the file
        $curl = curl_init($attachment['url']);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        $imageData = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode >= 200 && $httpCode < 300 && $imageData) {
            file_put_contents($cacheFile, $imageData);
            return $cacheFile;
        }

        return null;
    }

    /**
     * Cache API response data
     * 
     * @param string $endpoint
     * @param array $data
     * @return void
     * @throws RuntimeException
     */
    private function setCache(string $endpoint, array $data): void {
        if (!$this->isCacheEnabled()) {
            return;
        }
        
        if (!is_dir($this->cache_dir)) {
            if (!mkdir($this->cache_dir, 0755, true) && !is_dir($this->cache_dir)) {
                throw new RuntimeException("Failed to create cache directory: {$this->cache_dir}");
            }
        }
        
        // Process and cache attachments before caching the API response
        $data = $this->cacheAttachments($data);
        
        $filePath = $this->getCacheFilePath($endpoint);
        if ($filePath) {
            $result = file_put_contents($filePath, json_encode($data));
            if ($result === false) {
                throw new RuntimeException("Failed to write cache file");
            }
        }
    }

    /**
     * Delete cache file for a specific endpoint
     * 
     * @param string $endpoint
     * @return void
     */
    private function deleteCache(string $endpoint): void {
        if (!$this->isCacheEnabled()) {
            return;
        }
        
        $filePath = $this->getCacheFilePath($endpoint);
        if ($filePath && file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Invalidate all cache files (used when records are modified)
     * 
     * @return void
     */
    private function invalidateAllCache(): void {
        if (!$this->isCacheEnabled()) {
            return;
        }

        $files = glob($this->cache_dir . '/*.json');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Make API call to Airtable
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @param bool $ignoreCache Whether to ignore cache
     * @return array|null
     * @throws RuntimeException
     */
    private function apiCall(string $method, string $endpoint = '', ?array $data = null, bool $ignoreCache = false): ?array {
        $url = "https://api.airtable.com/v0/{$this->base_id}/{$this->table_name}/{$endpoint}";
        $cacheKey = "{$method}_{$url}_" . json_encode($data);
        
        // Check cache for GET requests
        if ($method === 'GET' && !$ignoreCache) {
            $cachedResponse = $this->getCache($cacheKey);
            if ($cachedResponse) {
                return $cachedResponse;
            }
        }

        $headers = [
            "Authorization: Bearer {$this->api_key}", 
            "Content-Type: application/json"
        ];
        
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }
        
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $data ? json_encode($data) : null,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false || !empty($curlError)) {
            throw new RuntimeException("cURL error: {$curlError}");
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['error']['message']) 
                ? $errorData['error']['message'] 
                : "HTTP {$httpCode}";
            throw new RuntimeException("Airtable API error: {$errorMessage}");
        }

        $decodedResponse = $httpCode >= 200 && $httpCode < 300 ? json_decode($response, true) : null;

        // Cache successful GET responses
        if ($method === 'GET' && $decodedResponse && !$ignoreCache) {
            $this->setCache($cacheKey, $decodedResponse);
        }
        
        return $decodedResponse;
    }

    /**
     * Get records from the table
     * 
     * @param string $view View name to filter by
     * @param string $filterByFormula Airtable formula to filter records
     * @param bool $ignoreCache Whether to ignore cache
     * @return array|null
     * @throws RuntimeException
     */
    public function getRecords(string $view = '', string $filterByFormula = '', bool $ignoreCache = false): ?array {
        $query = http_build_query(array_filter([
            'view' => $view,
            'filterByFormula' => $filterByFormula
        ]));
        $endpoint = $query ? "?{$query}" : '';
        return $this->apiCall('GET', $endpoint, null, $ignoreCache);
    }

    /**
     * Get a single record by ID
     * 
     * @param string $recordId Record ID
     * @param bool $ignoreCache Whether to ignore cache
     * @return array|null
     * @throws RuntimeException
     */
    public function getRecord(string $recordId, bool $ignoreCache = false): ?array {
        return $this->apiCall('GET', $recordId, null, $ignoreCache);
    }

    /**
     * Add a new record
     * 
     * @param array $data Field data for the new record
     * @return array|null
     * @throws RuntimeException
     */
    public function addRecord(array $data): ?array {
        return $this->apiCall('POST', '', ['fields' => $data], true);
    }

    /**
     * Update an existing record
     * 
     * @param string $recordId Record ID to update
     * @param array $data Field data to update
     * @return array|null
     * @throws RuntimeException
     */
    public function updateRecord(string $recordId, array $data): ?array {
        $updatePayload = [ 
            'records' => [
                [
                    'id' => $recordId,
                    'fields' => $data
                ]
            ]
        ];
        $result = $this->apiCall('PATCH', '', $updatePayload, true);
        if ($result) {
            $this->invalidateAllCache();
        }
        return $result;
    }

    /**
     * Delete a record
     * 
     * @param string $recordId Record ID to delete
     * @return array|null
     * @throws RuntimeException
     */
    public function deleteRecord(string $recordId): ?array {
        $result = $this->apiCall('DELETE', $recordId, null, true);
        if ($result) {
            $this->invalidateAllCache();
        }
        return $result;
    }
}
