<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowApplicationRequest;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\RecordApplyTargets;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 新規の申請(docs の 設計/ワークフロー/画面.md 1.1・3 節): 画面 Workflow/Apply(mode new。フローが無い 404 → 申請できる人でない 403)と、申請の書き込み。
 * 成功は案件の詳細へのリダイレクト(POST は 302 のまま = 案件の操作/同時実行と検査.md 4 節)と成功のトースト。検証の失敗は元の画面へ戻りキーにエラーが付く。
 * クエリ record(入力 record_id)があれば既存のデータからの申請(設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 3 節・4 節)。
 */
class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationFormQuery $forms,
        private ApplyCase $applyCase,
        private RecordApplyTargets $recordTargets,
    ) {}

    public function create(Request $request, string $flow): Response|RedirectResponse
    {
        $context = AccessContext::of($request);
        $baseDate = $request->query('base_date');
        $recordId = $this->recordIdOf($request->query('record'));

        if ($recordId === null) {
            return Inertia::render('Workflow/Apply', $this->forms->create($context, $flow, $baseDate));
        }

        $form = $this->forms->fromRecord($context, $flow, $recordId, $baseDate);

        if ($form === null) {
            // 読んだ後に消されたレコードは詳細も 404 なので、ここで 404 にする
            $module = $this->recordTargets->moduleCodeOf($recordId) ?? abort(404);
            Inertia::flash('toast', ['type' => 'error', 'message' => RecordApplicationSteps::HELD]);

            return redirect()->route('records.data.show', ['module' => $module, 'record' => $recordId]);
        }

        return Inertia::render('Workflow/Apply', $form);
    }

    public function store(FlowApplicationRequest $request, string $flow): RedirectResponse
    {
        $context = AccessContext::of($request);
        $recordId = $request->recordId();
        $input = $request->applicationInput();
        $baseDate = $request->baseDate();
        $result = $recordId === null
            ? $this->applyCase->execute($context, $flow, $input, $baseDate)
            : $this->applyCase->executeFromRecord(
                $context, $flow, $recordId, $request->recordVersion(), $input, $baseDate,
            );
        Inertia::flash('toast', ['type' => 'success', 'message' => '申請しました']);

        return redirect()->route('workflow.cases.show', ['case' => $result->caseId]);
    }

    /**
     * クエリ record(正の整数でない値は無いのと同じ = 新規の申請。設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 3 節)。
     */
    private function recordIdOf(mixed $value): ?int
    {
        $pattern = '/^[1-9][0-9]{0,'.(FlowApplicationRequest::RECORD_ID_MAX_DIGITS - 1).'}\z/';

        return is_string($value) && preg_match($pattern, $value) === 1 ? (int) $value : null;
    }
}
