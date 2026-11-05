<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Exceptions\InvalidCatalogSourceException;

class CatalogSourceConfigParser
{
    /**
     * Parse and validate the raw ASU_SOURCES JSON string.
     *
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     url: string,
     *     token: ?string,
     *     timeout: float,
     *     connect_timeout: float,
     *     search_path: string
     * }>
     *
     * @throws InvalidCatalogSourceException
     */
    public function parse(?string $rawJson): array
    {
        if ($rawJson === null || trim($rawJson) === '') {
            return [];
        }

        $decoded = json_decode(trim($rawJson), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidCatalogSourceException(
                'ASU_SOURCES contains invalid JSON: '.json_last_error_msg()
            );
        }

        if (! is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))) {
            throw new InvalidCatalogSourceException(
                'ASU_SOURCES root must be a JSON object mapping source keys to configurations.'
            );
        }

        $sources = [];

        foreach ($decoded as $key => $config) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidCatalogSourceException('ASU source key must be a non-empty string.');
            }

            $sourceKey = trim($key);

            if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $sourceKey)) {
                throw new InvalidCatalogSourceException(
                    "ASU source key [{$sourceKey}] is invalid. Keys must be alphanumeric with dashes or underscores."
                );
            }

            if (! is_array($config) || array_is_list($config)) {
                throw new InvalidCatalogSourceException(
                    "Configuration for source [{$sourceKey}] must be an object."
                );
            }

            // 1. Validate URL
            if (! isset($config['url']) || ! is_string($config['url']) || trim($config['url']) === '') {
                throw new InvalidCatalogSourceException(
                    "Source [{$sourceKey}] requires a valid URL."
                );
            }

            $url = trim($config['url']);
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidCatalogSourceException(
                    "Source [{$sourceKey}] URL [{$url}] must be a valid HTTP or HTTPS URL."
                );
            }

            // 2. Validate Name
            if (isset($config['name'])) {
                if (! is_string($config['name']) || trim($config['name']) === '') {
                    throw new InvalidCatalogSourceException(
                        "Source [{$sourceKey}] name must be a non-empty string."
                    );
                }
                $name = trim($config['name']);
            } else {
                $name = ucwords(str_replace(['_', '-'], ' ', $sourceKey));
            }

            // 3. Validate Timeout
            $timeout = 2.0;
            if (isset($config['timeout'])) {
                if (! is_numeric($config['timeout']) || (float) $config['timeout'] <= 0) {
                    throw new InvalidCatalogSourceException(
                        "Source [{$sourceKey}] timeout must be a positive number."
                    );
                }
                $timeout = (float) $config['timeout'];
            }

            // 4. Validate Connect Timeout
            $connectTimeout = 1.0;
            if (isset($config['connect_timeout'])) {
                if (! is_numeric($config['connect_timeout']) || (float) $config['connect_timeout'] <= 0) {
                    throw new InvalidCatalogSourceException(
                        "Source [{$sourceKey}] connect_timeout must be a positive number."
                    );
                }
                $connectTimeout = (float) $config['connect_timeout'];
            }

            // 5. Validate Search Path
            $searchPath = '/api/v1/s2s/catalog/search';
            if (isset($config['search_path'])) {
                if (! is_string($config['search_path']) || trim($config['search_path']) === '') {
                    throw new InvalidCatalogSourceException(
                        "Source [{$sourceKey}] search_path must be a non-empty string."
                    );
                }
                $searchPath = '/'.ltrim(trim($config['search_path']), '/');
            }

            // 6. Validate and Resolve Token
            $token = null;
            if (array_key_exists('token', $config) && $config['token'] !== null) {
                if (! is_string($config['token'])) {
                    throw new InvalidCatalogSourceException(
                        "Source [{$sourceKey}] token must be a string or null."
                    );
                }

                $rawToken = trim($config['token']);

                // If token references an environment variable like ${VAR_NAME} or $VAR_NAME
                if (preg_match('/^\$\{?([a-zA-Z0-9_]+)\}?$/', $rawToken, $matches)) {
                    $token = $this->resolveEnvSecret($matches[1]);
                } else {
                    $token = $rawToken !== '' ? $rawToken : null;
                }
            } else {
                // Fallback check for [KEY]_TOKEN (e.g. LARISSAMA_TOKEN)
                $fallbackVar = strtoupper(str_replace('-', '_', $sourceKey)).'_TOKEN';
                $token = $this->resolveEnvSecret($fallbackVar);
            }

            $sources[$sourceKey] = [
                'key' => $sourceKey,
                'name' => $name,
                'url' => $url,
                'token' => $token,
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'search_path' => $searchPath,
            ];
        }

        return $sources;
    }

    /**
     * Resolve environment secret safely without leaking or throwing.
     */
    protected function resolveEnvSecret(string $varName): ?string
    {
        $val = getenv($varName);
        if ($val !== false && is_string($val) && trim($val) !== '') {
            return trim($val);
        }

        if (isset($_ENV[$varName]) && is_string($_ENV[$varName]) && trim($_ENV[$varName]) !== '') {
            return trim($_ENV[$varName]);
        }

        if (isset($_SERVER[$varName]) && is_string($_SERVER[$varName]) && trim($_SERVER[$varName]) !== '') {
            return trim($_SERVER[$varName]);
        }

        return null;
    }
}
