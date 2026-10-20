<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Actions\Records\UpdateRecordModule;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordModuleCategoriesRequest;
use App\Http\Requests\Records\RecordModuleInputRequest;
use App\Http\Requests\Records\RecordModuleOrderRequest;
use App\Http\Requests\Records\RecordModuleUpdateRequest;
use App\Queries\Records\RecordAdminQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 管理の業務モジュールの画面と、作成・更新・「業務」の直下の並び替え・分類の更新(docs の 設計/業務データの入れ物/画面/README.md 1.1、
 * 設計/業務データの入れ物/画面/管理の画面.md 3.1〜3.3・3.5、設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3)。
 * 業務モジュールの判定をせず、ルートの認可だけで絞る(認可とメニュー/README.md 4 節)。
 */
class ModuleController extends Controller
{
    public function __construct(
        private RecordAdminQuery $admin,
        private CreateRecordModule $createModule,
        private UpdateRecordModule $updateModule,
        private ReorderRecordModuleItems $reorderItems,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Records/Admin/ModuleIndex', ['modules' => $this->admin->modules()]);
    }

    public function create(): Response
    {
        return Inertia::render('Records/Admin/ModuleCreate', $this->admin->createForm());
    }

    public function store(RecordModuleInputRequest $request): RedirectResponse
    {
        $created = $this->createModule->execute($request->moduleInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '業務モジュールを作成しました']);

        return redirect()->route('records.admin.modules.show', ['module' => $created->code], 303);
    }

    public function show(string $module): Response
    {
        return Inertia::render('Records/Admin/ModuleShow', $this->admin->module($module));
    }

    public function edit(string $module): Response
    {
        return Inertia::render('Records/Admin/ModuleEdit', $this->admin->moduleForm($module));
    }

    public function update(RecordModuleUpdateRequest $request, string $module): RedirectResponse
    {
        $change = $this->updateModule->execute($module, $request->moduleInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($change) {
            RecordModuleVisibilityChange::Hidden => '業務モジュールを更新し、サイドバーで非表示にしました',
            RecordModuleVisibilityChange::Shown => '業務モジュールを更新し、サイドバーに表示しました',
            RecordModuleVisibilityChange::Unchanged => '業務モジュールを更新しました',
        }]);

        return redirect()->route('records.admin.modules.show', ['module' => $module], 303);
    }

    public function reorder(RecordModuleOrderRequest $request): RedirectResponse
    {
        $this->reorderItems->execute(null, $request->codes());

        return redirect()->route('records.admin.categories.index', [], 303);
    }

    /**
     * 業務モジュールの分類を変える(分類の画面のドラッグ = モジュール分類/業務処理.md 3.11): 業務モジュールを変える業務処理に categories だけを渡し、
     * モジュール分類の画面へ 303(トーストなし = 落とした結果が見えている)。返り値の表示の変化は使わない。
     */
    public function updateCategories(RecordModuleCategoriesRequest $request, string $module): RedirectResponse
    {
        $this->updateModule->execute($module, $request->moduleInput());

        return redirect()->route('records.admin.categories.index', [], 303);
    }
}
