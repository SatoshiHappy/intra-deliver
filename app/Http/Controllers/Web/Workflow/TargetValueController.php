<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\TargetValuesRequest;
use App\Queries\Workflow\ApplicationTargetValueQuery;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 写しの値の入口(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.3 節): 申請の画面が申請対象を選んだときに、
 * ページ遷移なしに写しの値 { values, details } を JSON で取る。結果は判定を求める者で変わるので、Cache-Control: private, no-store を付ける。
 */
class TargetValueController extends Controller
{
    public function __construct(private ApplicationTargetValueQuery $applicationTargetValueQuery) {}

    public function __invoke(TargetValuesRequest $request, string $target): JsonResponse
    {
        $values = $this->applicationTargetValueQuery->valuesOf(
            AccessContext::of($request),
            (int) $target,
            $request->flow(),
            $request->caseId(),
            $request->applicant(),
            $request->recordId(),
        );

        return response()->json($values)->header('Cache-Control', 'private, no-store');
    }
}
