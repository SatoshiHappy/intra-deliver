<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 規則を作る・変える入力の入口の FormRequest(入力の形 = 設計/業務データの入れ物/画面/管理の画面.md 3.3)。
 * 見るのは入れ物の形だけ(condition はオブジェクト)。条件の中身と種別・対象などは業務処理が、入力の名前のキーの 422 で拒む
 * (項目と規則/規則と業務処理/README.md 8 節、失敗と定義の変更.md 11 節、設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)。
 */
class RecordRuleInputRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['kind', 'target', 'condition', 'operator', 'compared', 'message'];

    /**
     * ルートの引数が指す行(業務モジュール・規則)が無ければ、検証より前に 404(ModelNotFoundException をそのまま投げる)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
     */
    public function authorize(RecordAdminQuery $admin): bool
    {
        $rule = $this->route('rule');
        $admin->findTargets((string) $this->route('module'), null, is_string($rule) ? (int) $rule : null);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'condition' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'condition.array' => '条件の形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(送られたキーだけ)。
     *
     * @return array<string, mixed>
     */
    public function ruleInput(): array
    {
        return $this->only(self::KEYS);
    }
}
