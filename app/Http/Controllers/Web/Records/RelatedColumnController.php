<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\SetRecordRelatedColumns;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordRelatedColumnsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 管理の関連レコードの列(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 4 節・5 節): 参照元の業務モジュールの定義の画面の
 * 「列を選択」の Dialog から保存する。ルートの認可だけで判定する(認可とメニュー/README.md 4 節)。成功は定義の画面へ 303 と成功のトースト。
 * 検証の失敗は定義の画面へ戻り、入力の名前のキー(columns・module)にエラーが付く(Dialog の中に出す)。
 */
class RelatedColumnController extends Controller
{
    public function __construct(private SetRecordRelatedColumns $setColumns) {}

    public function update(RecordRelatedColumnsRequest $request, string $module): RedirectResponse
    {
        $this->setColumns->execute($module, $request->relatedColumnsInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '関連レコードの列を保存しました']);

        return redirect()->route('records.admin.modules.show', ['module' => $module], 303);
    }
}
