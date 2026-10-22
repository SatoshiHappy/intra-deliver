<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 見出しと項目の並び替えの入力の封筒(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3、レコードの共通機能/詳細の画面/見出し.md の 4 節):
 * { items: [{ kind: 'heading', id } | { kind: 'field', code }, …] }。入口が見るのは入れ物の形だけ(items が list)。
 * 中身(その業務モジュールの全見出しと全項目の順列か)は業務処理が確かめて、キー items の 422 で拒む(項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。
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
            'items' => ['present', 'bail', 'array', 'list'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.present' => '項目の並びの形が正しくありません',
            'items.array' => '項目の並びの形が正しくありません',
            'items.list' => '項目の並びの形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(見出しと項目の新しい順)。
     *
     * @return array<array-key, mixed>
     */
    public function items(): array
    {
        $items = $this->input('items');

        return is_array($items) ? $items : [];
    }
}
