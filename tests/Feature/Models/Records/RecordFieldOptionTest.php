<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\RecordFieldOption;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 選択肢(docs の 設計/業務データの入れ物/データモデル/ 1 節・2.3: コードは英小文字・数字・_ の 1〜50 文字で、数字で始まってよい。名称は空にしない)。
 */
final class RecordFieldOptionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCodes(): array
    {
        return [
            '大文字' => ['High'],
            '- を含む' => ['in-progress'],
            '51 文字' => [str_repeat('a', 51)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wellFormedCodes(): array
    {
        return [
            '先頭の数字' => ['1st'],
            '先頭の _' => ['_draft'],
            '50 文字' => [str_repeat('9', 50)],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_rejects_a_malformed_code(string $code): void
    {
        $this->assertRejectedBy('record_field_options_code_check', fn () => RecordFieldOption::factory()->create(['code' => $code]));
    }

    #[DataProvider('wellFormedCodes')]
    public function test_accepts_a_well_formed_code(string $code): void
    {
        $option = RecordFieldOption::factory()->create(['code' => $code]);

        $this->assertSame($code, $option->refresh()->code);
    }

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy('record_field_options_name_check', fn () => RecordFieldOption::factory()->create(['name' => '']));
    }
}
