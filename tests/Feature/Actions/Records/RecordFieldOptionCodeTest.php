<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Models\Records\RecordField;
use App\Support\DefinitionText;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 選択肢のコードの長さの上限(docs の 設計/業務データの入れ物/データモデル/ 1 節): 業務モジュール・項目のコードと同じ DefinitionText::CODE_MAX 文字まで。
 * 選択肢のコードのほかの誤りは RecordFieldDefinitionTest。
 */
final class RecordFieldOptionCodeTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    public function test_accepts_an_option_code_of_the_code_max_and_rejects_one_character_more(): void
    {
        $this->recordModule('projects');

        $tooLong = $this->validationErrors(fn () => $this->addChoice(str_repeat('1', DefinitionText::CODE_MAX + 1)));
        $field = $this->addChoice(str_repeat('1', DefinitionText::CODE_MAX));

        $this->assertSame(['options.0.code' => 'コードは英小文字・数字・_ の 50 文字までにしてください'], $tooLong);
        $this->assertSame([str_repeat('1', DefinitionText::CODE_MAX)], $field->recordFieldOptions->pluck('code')->all());
    }

    private function addChoice(string $optionCode): RecordField
    {
        return app(AddRecordField::class)->execute('projects', ['code' => 'state', 'name' => '状態', 'type' => 'choice', 'options' => [['code' => $optionCode, 'name' => '状態']]]);
    }
}
