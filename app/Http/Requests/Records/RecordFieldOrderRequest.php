<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 項目の並び替えの入力の封筒(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3): { codes: [<項目のコード>, …] }。
 * 入口が見るのは入れ物の形だけ(codes が list)。中身(その業務モジュールの全項目のコードの順列か)は業務処理が確かめて、キー codes の 422 で拒む
 * (項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。
 */
class RecordFieldOrderRequest extends FormRequest
{
    /**
     * ルートの引数が指す業務モジュール(明細のモジュールを含む)が無ければ、検証より前に 404(項目の FormRequest と同じ = 認可とメニュー/README.md 4 節)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない。
     */
    public function authorize(RecordAdminQuery $admin): bool
    {
        $admin->findTargets((string) $this->route('module'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'codes' => ['present', 'bail', 'array', 'list'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codes.present' => '項目の並びの形が正しくありません',
            'codes.array' => '項目の並びの形が正しくありません',
            'codes.list' => '項目の並びの形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(項目のコードの新しい順)。
     *
     * @return array<array-key, mixed>
     */
    public function codes(): array
    {
        $codes = $this->input('codes');

        return is_array($codes) ? $codes : [];
    }
}
