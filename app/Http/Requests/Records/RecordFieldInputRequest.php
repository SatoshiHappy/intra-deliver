<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 項目を足す・変える入力の入口の FormRequest(入力の形 = 設計/業務データの入れ物/画面/管理の画面.md 3.4)。
 * 見るのは入れ物の形だけ(options は行のリストで行は配列、child_module は配列。中のキーと値は業務処理が見る)。値の中身は業務処理が、入力の名前のキーの 422 で拒む
 * (項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)。送られたキーだけを渡す。
 */
class RecordFieldInputRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = [
        'code', 'name', 'type', 'is_required', 'is_multiline', 'is_multiple', 'max_length', 'default_value', 'options', 'referenced_module', 'child_module',
        'is_listed', 'is_searchable', 'is_title', 'grants_row_access', 'sort_key',
    ];

    /**
     * ルートの引数が指す行(業務モジュール・項目)が無ければ、検証より前に 404(ModelNotFoundException をそのまま投げる)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
     */
    public function authorize(RecordAdminQuery $admin): bool
    {
        $field = $this->route('field');
        $admin->findTargets((string) $this->route('module'), is_string($field) ? $field : null);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'options' => ['nullable', 'bail', 'array', 'list'],
            'options.*' => ['array'],
            'child_module' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.array' => '選択肢の形が正しくありません',
            'options.list' => '選択肢の形が正しくありません',
            'options.*.array' => '選択肢の形が正しくありません',
            'child_module.array' => '明細のモジュールの形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(送られたキーだけ)。
     *
     * @return array<string, mixed>
     */
    public function fieldInput(): array
    {
        return $this->only(self::KEYS);
    }
}
