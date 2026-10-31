<?php

namespace App\Http\Requests\Workflow;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 版の追加 workflow.admin.versions.store の入力 { start_date }(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 4 節)。
 * 入口は在るかと日付の形(Y-m-d)を見て、範囲は業務処理が 422 start_date にする(文言 = 6 節)。
 */
class FlowVersionRequest extends FlowDefinitionRequest
{
    public const START_DATE_REQUIRED = '開始日を入力してください';

    public const START_DATE_NOT_DATE = '開始日は日付で入力してください';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['start_date' => ['bail', 'required', 'string', 'date_format:Y-m-d']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['start_date.required' => self::START_DATE_REQUIRED, 'start_date' => self::START_DATE_NOT_DATE];
    }

    /**
     * 開始日(規則が形を確かめた後に呼ぶ。日付だけを使う)。
     */
    public function startDate(): CarbonImmutable
    {
        $value = $this->string('start_date')->toString();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, AccessContext::TIMEZONE);

        return $date ?: throw new LogicException('検証を通った開始日を読めない');
    }
}
