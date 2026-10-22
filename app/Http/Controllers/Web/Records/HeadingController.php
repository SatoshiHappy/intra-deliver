<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\DeleteRecordFieldHeading;
use App\Actions\Records\UpdateRecordFieldHeading;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordHeadingInputRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 管理の見出し(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 5 節、詳細の画面/見出し.md の 5 節): 業務モジュールの定義の画面の Dialog から、追加・更新・削除。
 * ルートの認可だけで判定する(認可とメニュー/README.md 4 節)。成功は定義の画面へ 303 と成功のトースト。検証の失敗は定義の画面へ戻り、
 * 入力の名前のキー(name・module・sort_key)にエラーが付く(Dialog の中に出す)。
 */
class HeadingController extends Controller
{
    public function __construct(
        private AddRecordFieldHeading $addHeading,
        private UpdateRecordFieldHeading $updateHeading,
        private DeleteRecordFieldHeading $deleteHeading,
    ) {}

    public function store(RecordHeadingInputRequest $request, string $module): RedirectResponse
    {
        $this->addHeading->execute($module, $request->headingInput());

        return $this->toDefinition($module, '見出しを追加しました');
    }

    public function update(RecordHeadingInputRequest $request, string $module, string $heading): RedirectResponse
    {
        $this->updateHeading->execute($module, (int) $heading, $request->headingInput());

        return $this->toDefinition($module, '見出しを更新しました');
    }

    public function destroy(string $module, string $heading): RedirectResponse
    {
        $this->deleteHeading->execute($module, (int) $heading);

        return $this->toDefinition($module, '見出しを削除しました');
    }

    /**
     * 業務モジュールの定義の画面へ、成功のトーストを付けて戻す。
     */
    private function toDefinition(string $module, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('records.admin.modules.show', ['module' => $module], 303);
    }
}
