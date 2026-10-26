<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowApplicationRequest;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\ApplicationTargetForm;
use App\Queries\Workflow\RecordApplyTargets;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 新規の申請(docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 3 節):
 * 画面 Workflow/Apply(mode new。フローが無い 404 → 申請できる人でない 403)と、申請の書き込み。
 * 成功は案件の詳細へのリダイレクト(POST は 302 のまま = 案件の操作/同時実行と検査.md 4 節)と成功のトースト。検証の失敗は元の画面へ戻りキーにエラーが付く。
 * クエリ record(入力 record_id)があれば既存のデータからの申請(設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節・4 節)。
 * クエリ target があれば申請対象からの申請の画面(設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.3 節)。
 * クエリ applicant(選ぶ代理元)と入力 applicant_user_id・contact = 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節。
 */
class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationFormQuery $forms,
        private ApplyCase $applyCase,
        private RecordApplyTargets $recordTargets,
        private ApplicationTargetForm $applicationTargetForms,
    ) {}

    public function create(Request $request, string $flow): Response|RedirectResponse
    {
        $context = AccessContext::of($request);
        $baseDate = $request->query('base_date');
        $applicantId = $request->query('applicant');
        $recordId = PositiveIds::of($request->query('record'));
        $applicationTargetId = PositiveIds::of($request->query('target'));

        if ($recordId === null) {
            return Inertia::render('Workflow/Apply', $applicationTargetId === null
                ? $this->forms->create($context, $flow, $baseDate, $applicantId)
                : $this->applicationTargetForms->fromApplicationTarget(
                    $context, $flow, $applicationTargetId, $applicantId, $baseDate,
                ));
        }

        $form = $this->forms->fromRecord($context, $flow, $recordId, $baseDate, $applicantId);

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
        [$applicantUserId, $contact] = [$request->applicantUserId(), $request->contact()];
        $result = $recordId === null
            ? $this->applyCase->execute($context, $flow, $input, $baseDate, $applicantUserId, $contact)
            : $this->applyCase->executeFromRecord(
                $context, $flow, $recordId, $request->recordVersion(), $input, $baseDate, $applicantUserId, $contact,
            );
        Inertia::flash('toast', ['type' => 'success', 'message' => '申請しました']);

        return redirect()->route('workflow.cases.show', ['case' => $result->caseId]);
    }
}
