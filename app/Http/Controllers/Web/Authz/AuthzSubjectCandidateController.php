<?php

namespace App\Http\Controllers\Web\Authz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\AuthzSubjectCandidatesRequest;
use App\Queries\Authz\SubjectColumnCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 「列を追加」の候補の口(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1、ADR 0006): ダイアログが、ページ遷移なしに種類の候補を JSON で取る。
 * 基準日・ロケールは {@see AccessContext} から取る。結果は基準日で変わるので、Cache-Control: private, no-store を付ける。
 */
class AuthzSubjectCandidateController extends Controller
{
    public function __construct(private SubjectColumnCandidates $candidates) {}

    public function __invoke(AuthzSubjectCandidatesRequest $request): JsonResponse
    {
        $context = AccessContext::of($request);
        $candidates = $this->candidates->of(
            $request->kind(),
            $request->keyword(),
            $context->date,
            $context->localeCode,
        );

        return response()->json(['candidates' => $candidates])->header('Cache-Control', 'private, no-store');
    }
}
