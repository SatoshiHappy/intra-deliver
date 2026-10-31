<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\AssigneeCandidatesRequest;
use App\Queries\Workflow\AssigneeCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 処理対象者の候補の口(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1・画面/案件の側の画面.md 6 節、ADR 0006): 承認のダイアログの「指定するステップ」の「ユーザーを検索して追加」が、
 * ページ遷移なしに候補を JSON で取る。結果は判定する人で変わるので、Cache-Control: private, no-store を付ける。
 */
class AssigneeCandidateController extends Controller
{
    public function __construct(private AssigneeCandidates $candidates) {}

    public function __invoke(AssigneeCandidatesRequest $request): JsonResponse
    {
        $users = $this->candidates->of(AccessContext::of($request), $request->caseId(), $request->nodeId(), $request->keyword());

        return response()->json(['users' => $users])->header('Cache-Control', 'private, no-store');
    }
}
