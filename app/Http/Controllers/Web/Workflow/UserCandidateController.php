<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\UserCandidatesRequest;
use App\Queries\Workflow\UserCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 人の候補の口(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節): 人を選ぶ欄の「ユーザーを検索して追加」が、ページ遷移なしに
 * 候補を JSON で取る(形は処理対象者の候補の口と同じ { users })。結果は判定する人で変わるので、Cache-Control: private, no-store を付ける。
 */
class UserCandidateController extends Controller
{
    public function __construct(private UserCandidates $candidates) {}

    public function __invoke(UserCandidatesRequest $request): JsonResponse
    {
        $context = AccessContext::of($request);
        $users = $this->candidates->of($context, $request->caseId(), $request->on(), $request->keyword());

        return response()->json(['users' => $users])->header('Cache-Control', 'private, no-store');
    }
}
