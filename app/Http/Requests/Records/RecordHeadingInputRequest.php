<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 見出しを足す・変える入力の入口の FormRequest(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 5 節: { name })。
 * 見るのは形だけ(規則なし)。名前の空・長さと明細のモジュールの拒否は業務処理が確かめて、入力の名前のキーの 422 で拒む。
 */
class RecordHeadingInputRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['name'];

    /**
     * ルートの引数が指す行(業務モジュール(明細のモジュールを含む)・見出し)が無ければ、検証より前に 404(項目の FormRequest と同じ)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
     */
    public function authorize(RecordAdminQuery $admin): bool
    {
        $heading = $this->route('heading');
        $admin->findTargets((string) $this->route('module'), null, null, is_string($heading) ? (int) $heading : null);

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
    public function headingInput(): array
    {
        return $this->only(self::KEYS);
    }
}
