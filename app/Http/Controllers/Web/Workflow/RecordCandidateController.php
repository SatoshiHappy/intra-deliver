<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\RecordCandidateRequest;
use App\Queries\Workflow\ApplicationRecordCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 申請の画面の参照項目の候補の口(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/README.md の 4.5 節): 申請の画面の参照の選択欄が、
 * ページ遷移なしに候補を JSON で取る(形は業務データの入れ物の画面の候補の口と同じ { records })。結果は判定を求める者で変わるので、Cache-Control: private, no-store を付ける。
 */
class RecordCandidateController extends Controller
{
    public function __construct(private ApplicationRecordCandidates $candidates) {}

    public function __invoke(RecordCandidateRequest $request, string $module): JsonResponse
    {
        $context = AccessContext::of($request);
        $records = $this->candidates->candidates(
            $context, $module, $request->formQuery(), $request->keyword(), $request->excludedId(),
        );

        return response()->json(['records' => $records])->header('Cache-Control', 'private, no-store');
    }
}
