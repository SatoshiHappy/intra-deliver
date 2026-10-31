<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowSubjectCandidatesRequest;
use App\Queries\Workflow\SubjectCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 定義の管理の対象者の候補の口(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、ADR 0006): ステップの設定と参照者の対象者の行が、
 * ページ遷移なしに候補を JSON で取る。基準日 = 今日。結果は基準日で変わるので、Cache-Control: private, no-store を付ける。
 */
class SubjectCandidateController extends Controller
{
    public function __construct(private SubjectCandidates $candidates) {}

    public function __invoke(FlowSubjectCandidatesRequest $request): JsonResponse
    {
        $context = AccessContext::of($request);
        $candidates = $this->candidates
            ->of($request->type(), $request->keyword(), $context->date, $context->localeCode);

        return response()->json(['candidates' => $candidates])->header('Cache-Control', 'private, no-store');
    }
}
