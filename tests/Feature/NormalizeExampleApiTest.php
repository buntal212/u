<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NormalizeExampleApiTest extends TestCase
{
    public function test_success_returns_normalized_data_and_preserves_the_original_input(): void
    {
        $original = "  Udumbara\t   Internal\nHub  ";

        $this->postJson('/api/v1/examples/normalize', ['value' => $original])
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson([
                'data' => [
                    'original' => $original,
                    'normalized' => 'Udumbara Internal Hub',
                ],
            ]);
    }

    public function test_unicode_whitespace_is_collapsed_without_changing_unicode_text(): void
    {
        $original = "\u{00A0}東京\t\u{2003}🚀\u{00A0}";

        $this->postJson('/api/v1/examples/normalize', ['value' => $original])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'original' => $original,
                    'normalized' => '東京 🚀',
                ],
            ]);
    }

    public function test_a_value_at_the_one_thousand_character_limit_is_accepted(): void
    {
        $value = str_repeat('界', 1000);

        $this->postJson('/api/v1/examples/normalize', ['value' => $value])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'original' => $value,
                    'normalized' => $value,
                ],
            ]);
    }

    public function test_a_value_over_the_one_thousand_character_limit_returns_422(): void
    {
        $this->postJson('/api/v1/examples/normalize', ['value' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Field value maksimal 1000 karakter.');
    }

    #[DataProvider('emptyValues')]
    public function test_empty_or_whitespace_only_values_return_422(mixed $value): void
    {
        $this->postJson('/api/v1/examples/normalize', ['value' => $value])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Field value wajib diisi.');
    }

    public static function emptyValues(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['   '],
            'unicode whitespace' => ["\u{00A0}\u{2003}"],
        ];
    }

    public function test_a_missing_value_returns_422(): void
    {
        $this->postJson('/api/v1/examples/normalize', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Field value wajib diisi.');
    }

    #[DataProvider('invalidValues')]
    public function test_a_non_string_value_returns_422(mixed $value): void
    {
        $this->postJson('/api/v1/examples/normalize', ['value' => $value])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Field value harus berupa teks.');
    }

    public static function invalidValues(): array
    {
        return [
            'array' => [['Udumbara']],
            'integer' => [42],
            'boolean' => [true],
        ];
    }

    public function test_unexpected_fields_return_422_with_a_field_error(): void
    {
        $this->postJson('/api/v1/examples/normalize', [
            'value' => 'Udumbara',
            'debug' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.debug.0', 'Field debug tidak didukung.');
    }

    public function test_malformed_json_returns_a_predictable_422_validation_response(): void
    {
        $this->call(
            'POST',
            '/api/v1/examples/normalize',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"value":',
        )->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Field value wajib diisi.');
    }

    public function test_the_reference_route_is_available_only_in_local_and_testing(): void
    {
        config(['app.env' => 'local']);

        $this->postJson('/api/v1/examples/normalize', ['value' => 'Udumbara'])
            ->assertOk();

        config(['app.env' => 'testing']);

        $this->postJson('/api/v1/examples/normalize', ['value' => 'Udumbara'])
            ->assertOk();
    }

    public function test_production_environment_returns_json_404_for_the_reference_route(): void
    {
        config(['app.env' => 'production']);

        $this->postJson('/api/v1/examples/normalize', ['value' => 'Udumbara'])
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Not Found.');
    }
}
