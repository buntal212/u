<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogSourceConfigParser;
use App\Services\Catalog\Exceptions\InvalidCatalogSourceException;
use PHPUnit\Framework\TestCase;

class CatalogSourceConfigParserTest extends TestCase
{
    protected CatalogSourceConfigParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CatalogSourceConfigParser;
    }

    // 1. Parsing JSON valid
    public function test_parses_valid_json_with_single_and_multiple_sources(): void
    {
        $json = json_encode([
            'larissama' => [
                'name' => 'Larissama F&B',
                'url' => 'http://larissama-api:8000',
                'token' => 'literal-secret-123',
                'timeout' => 2.5,
                'connect_timeout' => 1.2,
                'search_path' => '/api/v1/s2s/catalog/search',
            ],
            'laundry' => [
                'url' => 'http://laundry-api:8000',
                'timeout' => 3.0,
            ],
        ]);

        $sources = $this->parser->parse($json);

        $this->assertCount(2, $sources);
        $this->assertArrayHasKey('larissama', $sources);
        $this->assertArrayHasKey('laundry', $sources);

        $larissama = $sources['larissama'];
        $this->assertSame('larissama', $larissama['key']);
        $this->assertSame('Larissama F&B', $larissama['name']);
        $this->assertSame('http://larissama-api:8000', $larissama['url']);
        $this->assertSame('literal-secret-123', $larissama['token']);
        $this->assertSame(2.5, $larissama['timeout']);
        $this->assertSame(1.2, $larissama['connect_timeout']);
        $this->assertSame('/api/v1/s2s/catalog/search', $larissama['search_path']);

        $laundry = $sources['laundry'];
        $this->assertSame('laundry', $laundry['key']);
        $this->assertSame('Laundry', $laundry['name']); // Humanized default name
        $this->assertSame('http://laundry-api:8000', $laundry['url']);
        $this->assertSame(3.0, $laundry['timeout']);
        $this->assertSame(1.0, $laundry['connect_timeout']); // Default connect_timeout
        $this->assertSame('/api/v1/s2s/catalog/search', $laundry['search_path']); // Default search_path
    }

    // 2. Empty input returns empty array
    public function test_empty_or_null_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->parser->parse(null));
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('   '));
        $this->assertSame([], $this->parser->parse('{}'));
    }

    // 3. Invalid JSON syntax throws exception
    public function test_invalid_json_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('ASU_SOURCES contains invalid JSON');

        $this->parser->parse('{invalid-json: true}');
    }

    // 4. Root not an object throws exception
    public function test_root_not_object_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('ASU_SOURCES root must be a JSON object');

        $this->parser->parse('["larissama", "laundry"]');
    }

    public function test_root_scalar_string_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('ASU_SOURCES root must be a JSON object');

        $this->parser->parse('"just a string"');
    }

    // 5. Source config not an object throws exception
    public function test_source_config_not_an_object_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Configuration for source [larissama] must be an object.');

        $this->parser->parse('{"larissama": "http://larissama-api:8000"}');
    }

    // 6. Source key invalid
    public function test_invalid_source_key_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('ASU source key [invalid key with spaces] is invalid.');

        $this->parser->parse('{"invalid key with spaces": {"url": "http://example.com"}}');
    }

    // 7. Source missing URL
    public function test_missing_url_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] requires a valid URL.');

        $this->parser->parse('{"larissama": {"timeout": 2}}');
    }

    // 8. Source URL invalid format
    public function test_invalid_url_format_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('URL [ftp://invalid-scheme.com] must be a valid HTTP or HTTPS URL.');

        $this->parser->parse('{"larissama": {"url": "ftp://invalid-scheme.com"}}');
    }

    // 9. Timeout invalid (zero, negative, non-numeric)
    public function test_invalid_timeout_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] timeout must be a positive number.');

        $this->parser->parse('{"larissama": {"url": "http://example.com", "timeout": 0}}');
    }

    public function test_negative_timeout_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] timeout must be a positive number.');

        $this->parser->parse('{"larissama": {"url": "http://example.com", "timeout": -2}}');
    }

    // 10. Connect timeout invalid
    public function test_invalid_connect_timeout_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] connect_timeout must be a positive number.');

        $this->parser->parse('{"larissama": {"url": "http://example.com", "connect_timeout": "invalid"}}');
    }

    // 11. Search path invalid
    public function test_invalid_search_path_throws_exception(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] search_path must be a non-empty string.');

        $this->parser->parse('{"larissama": {"url": "http://example.com", "search_path": ""}}');
    }

    // 12. Token resolution via ${VAR_NAME} environment variable
    public function test_resolves_env_variable_interpolation_for_token(): void
    {
        putenv('TEST_PARSER_LARISSAMA_TOKEN=resolved-secret-abc-999');

        $json = json_encode([
            'larissama' => [
                'url' => 'http://larissama-api:8000',
                'token' => '${TEST_PARSER_LARISSAMA_TOKEN}',
            ],
        ]);

        $sources = $this->parser->parse($json);

        $this->assertSame('resolved-secret-abc-999', $sources['larissama']['token']);

        putenv('TEST_PARSER_LARISSAMA_TOKEN');
    }

    // 13. Token resolution via fallback [KEY]_TOKEN environment variable
    public function test_resolves_fallback_env_token_when_token_field_omitted(): void
    {
        putenv('LAUNDRY_TOKEN=fallback-laundry-token-xyz');

        $json = json_encode([
            'laundry' => [
                'url' => 'http://laundry-api:8000',
            ],
        ]);

        $sources = $this->parser->parse($json);

        $this->assertSame('fallback-laundry-token-xyz', $sources['laundry']['token']);

        putenv('LAUNDRY_TOKEN');
    }

    // 14. Token validation ensures secret does not leak in error message
    public function test_invalid_token_type_throws_exception_without_leaking_secrets(): void
    {
        $this->expectException(InvalidCatalogSourceException::class);
        $this->expectExceptionMessage('Source [larissama] token must be a string or null.');

        $this->parser->parse('{"larissama": {"url": "http://example.com", "token": ["array", "token"]}}');
    }

    // 15. Ensure token value is never leaked in validation errors
    public function test_token_value_is_never_leaked_in_exception_messages(): void
    {
        $secretToken = 'super-secret-do-not-leak-99999';

        try {
            $this->parser->parse(json_encode([
                'larissama' => [
                    'url' => 'http://example.com',
                    'token' => $secretToken,
                    'timeout' => -5, // Intentional error
                ],
            ]));
            $this->fail('Expected InvalidCatalogSourceException was not thrown.');
        } catch (InvalidCatalogSourceException $e) {
            $this->assertStringNotContainsString($secretToken, $e->getMessage());
        }
    }
}
