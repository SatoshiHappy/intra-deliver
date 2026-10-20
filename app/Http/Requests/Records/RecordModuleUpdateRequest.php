<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 業務モジュールを変える入力の封筒(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5): { name, description, icon, is_visible, categories,
 * row_access_scope }(見える範囲 = 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 2 節)。
 * 入口で見る形は無い(規則なし。categories の配列の形も業務処理が見る = モジュール分類/業務処理.md 3.7)。値の中身(名前の空・長さ・U+0000、説明の長さ、
 * アイコンが選択肢にあること、is_visible が真偽であること、categories が重ならない分類 ID の配列で表示中だけ空でないこと、明細のモジュールの
 * アイコンと表示の状態と分類、見える範囲の値と明細のモジュールの見える範囲)は業務処理が確かめて、入力の名前のキーの 422 で拒む(設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)。
 * 送られたキーだけを渡す(入力に無いキーは業務処理が今の値のままにする)。ほかのキーは受け取らない。
 */
class RecordModuleUpdateRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['name', 'description', 'icon', 'is_visible', 'categories', 'row_access_scope'];

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
        return [];
    }

    /**
     * 業務処理の入力(送られたキーだけ)。
     *
     * @return array<string, mixed>
     */
    public function moduleInput(): array
    {
        return $this->only(self::KEYS);
    }
}
