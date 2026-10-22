<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 関連レコードの列を選ぶ入力の入口の FormRequest(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 5 節:
 * { entry, columns })。見るのは形だけ(規則なし)。入口の項目の 404 と、明細のモジュール・列の 422 は業務処理が確かめる。
 */
class RecordRelatedColumnsRequest extends FormRequest
{
    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['entry', 'columns'];

    /**
     * ルートの引数が指す業務モジュール(明細のモジュールを含む)が無ければ、検証より前に 404(見出しの FormRequest と同じ)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
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
    public function relatedColumnsInput(): array
    {
        return $this->only(self::KEYS);
    }
}
