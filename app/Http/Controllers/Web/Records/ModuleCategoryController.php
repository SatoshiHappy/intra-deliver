<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\CreateModuleCategory;
use App\Actions\Records\DeleteModuleCategory;
use App\Actions\Records\RenameModuleCategory;
use App\Actions\Records\ReorderModuleCategories;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Actions\Records\SetModuleCategoryModules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\ModuleCategoryInputRequest;
use App\Http\Requests\Records\ModuleCategoryModulesRequest;
use App\Http\Requests\Records\ModuleCategoryOrderRequest;
use App\Http\Requests\Records\RecordModuleOrderRequest;
use App\Queries\Records\ModuleCategoryPageQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * モジュール分類の画面(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4、画面/README.md 1.1): 画面 Records/Admin/ModuleCategories と、
 * 分類の作成・名前の変更・削除・並び替え・分類の中の並び替え・分類の中の業務モジュールを揃える(「業務モジュールの追加」の Dialog = 業務処理.md 3.10)
 * (「業務」の直下の並び替えと、ドラッグでの業務モジュールの分類の更新は {@see ModuleController})。
 * ルートの認可だけで判定する(システム管理者だけ = 認可とメニュー/README.md 4 節)。書き込みの成功はモジュール分類の画面へ 303 と成功のトースト
 * (並び替えはトーストなし = ドロップで並びが変わって見えている)。検証の失敗は元の画面へ戻り、入力の名前のキー(name・category・ids・codes)にエラーが付く。
 * {category} = 分類 ID。無い分類は 404(FormRequest の authorize が入力の検証より先に引く。削除は業務処理が引く)。
 */
class ModuleCategoryController extends Controller
{
    public function __construct(
        private ModuleCategoryPageQuery $page,
        private CreateModuleCategory $createCategory,
        private RenameModuleCategory $renameCategory,
        private DeleteModuleCategory $deleteCategory,
        private ReorderModuleCategories $reorderCategories,
        private ReorderRecordModuleItems $reorderItems,
        private SetModuleCategoryModules $setModules,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Records/Admin/ModuleCategories', $this->page->props());
    }

    public function store(ModuleCategoryInputRequest $request): RedirectResponse
    {
        $this->createCategory->execute($request->categoryInput());

        return $this->toCategories('モジュール分類を作成しました');
    }

    public function update(ModuleCategoryInputRequest $request, string $category): RedirectResponse
    {
        $this->renameCategory->execute($category, $request->categoryInput());

        return $this->toCategories('モジュール分類を更新しました');
    }

    public function destroy(string $category): RedirectResponse
    {
        $this->deleteCategory->execute($category);

        return $this->toCategories('モジュール分類を削除しました');
    }

    public function reorder(ModuleCategoryOrderRequest $request): RedirectResponse
    {
        $this->reorderCategories->execute($request->ids());

        return redirect()->route('records.admin.categories.index', [], 303);
    }

    public function reorderModules(RecordModuleOrderRequest $request, string $category): RedirectResponse
    {
        $this->reorderItems->execute($category, $request->codes());

        return redirect()->route('records.admin.categories.index', [], 303);
    }

    public function updateModules(ModuleCategoryModulesRequest $request, string $category): RedirectResponse
    {
        $this->setModules->execute($category, $request->codes());

        return $this->toCategories('モジュール分類の業務モジュールを更新しました');
    }

    /**
     * モジュール分類の画面へ、成功のトーストを付けて戻す。
     */
    private function toCategories(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('records.admin.categories.index', [], 303);
    }
}
