<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\ApplicationTargetValueQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 写しの値の入口のクエリ { flow, case, applicant, record }(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の
 * 10.3 節)。どれも解釈できない値を誤りにせず読み取りが解釈するので、検証の規則は無い。404・403 は {@see ApplicationTargetValueQuery} が判定する。
 */
class TargetValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function flow(): mixed
    {
        return $this->query('flow');
    }

    public function caseId(): mixed
    {
        return $this->query('case');
    }

    public function applicant(): mixed
    {
        return $this->query('applicant');
    }

    public function recordId(): mixed
    {
        return $this->query('record');
    }
}
