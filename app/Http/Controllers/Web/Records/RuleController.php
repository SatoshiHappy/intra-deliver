<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\AddRecordRule;
use App\Actions\Records\DeleteRecordRule;
use App\Actions\Records\UpdateRecordRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordRuleInputRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 管理の規則(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/管理の画面.md 3.3): 業務モジュールの定義の画面の Dialog から、追加・更新・削除。
 * ルートの認可だけで判定する(認可とメニュー/README.md 4 節)。成功は定義の画面へ 303 と成功のトースト。検証の失敗は定義の画面へ戻り、
 * 入力の名前のキー(target・condition.all.<添字>.value など)にエラーが付く(Dialog の中に出す)。
 */
class RuleController extends Controller
{
    public function __construct(
        private AddRecordRule $addRule,
        private UpdateRecordRule $updateRule,
        private DeleteRecordRule $deleteRule,
    ) {}

    public function store(RecordRuleInputRequest $request, string $module): RedirectResponse
    {
        $this->addRule->execute($module, $request->ruleInput());

        return $this->toDefinition($module, '規則を追加しました');
    }

    public function update(RecordRuleInputRequest $request, string $module, string $rule): RedirectResponse
    {
        $this->updateRule->execute($module, (int) $rule, $request->ruleInput());

        return $this->toDefinition($module, '規則を更新しました');
    }

    public function destroy(string $module, string $rule): RedirectResponse
    {
        $this->deleteRule->execute($module, (int) $rule);

        return $this->toDefinition($module, '規則を削除しました');
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
