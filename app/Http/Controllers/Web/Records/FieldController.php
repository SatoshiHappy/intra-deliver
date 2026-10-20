<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\UpdateRecordField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordFieldInputRequest;
use App\Http\Requests\Records\RecordFieldOrderRequest;
use App\Queries\Records\RecordAdminQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 管理の項目の画面(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/管理の画面.md 3.3・3.4): 追加・編集 Records/Admin/FieldForm と、追加・更新・削除・並び替え。
 * ルートの認可だけで判定する(認可とメニュー/README.md 4 節)。成功は業務モジュールの定義の画面へ 303 と成功のトースト(並び替えはトーストなし = 画面/README.md 1.1)。
 * 検証の失敗は元の画面へ戻り、入力の名前のキーにエラーが付く(削除を規則が拒むときはキー field、並び替えの順列の誤りはキー codes = 項目と規則/規則と業務処理/失敗と定義の変更.md 11 節。
 * 定義の画面がトーストで出す)。
 */
class FieldController extends Controller
{
    public function __construct(
        private RecordAdminQuery $admin,
        private AddRecordField $addField,
        private UpdateRecordField $updateField,
        private DeleteRecordField $deleteField,
        private ReorderRecordFields $reorderFields,
    ) {}

    public function create(string $module): Response
    {
        return Inertia::render('Records/Admin/FieldForm', $this->admin->fieldForm($module, null));
    }

    public function store(RecordFieldInputRequest $request, string $module): RedirectResponse
    {
        $this->addField->execute($module, $request->fieldInput());

        return $this->toDefinition($module, '項目を追加しました');
    }

    public function edit(string $module, string $field): Response
    {
        return Inertia::render('Records/Admin/FieldForm', $this->admin->fieldForm($module, $field));
    }

    public function update(RecordFieldInputRequest $request, string $module, string $field): RedirectResponse
    {
        $this->updateField->execute($module, $field, $request->fieldInput());

        return $this->toDefinition($module, '項目を更新しました');
    }

    public function destroy(string $module, string $field): RedirectResponse
    {
        $this->deleteField->execute($module, $field);

        return $this->toDefinition($module, '項目を削除しました');
    }

    public function reorder(RecordFieldOrderRequest $request, string $module): RedirectResponse
    {
        $this->reorderFields->execute($module, $request->codes());

        return redirect()->route('records.admin.modules.show', ['module' => $module], 303);
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
