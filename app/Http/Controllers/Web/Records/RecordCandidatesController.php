<?php

namespace App\Http\Controllers\Web\Records;

use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordCandidatesRequest;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordCandidatesQuery;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 他モジュール参照の項目の候補の口(docs の 設計/業務データの入れ物/画面/README.md 4 節、ADR 0006・0008): フォームと一覧の検索欄の参照の選択欄が、
 * ページ遷移なしに URL の業務モジュールのレコードを JSON で取る。ルートの認可と FormRequest を通り、読み取りが業務モジュールの read を判定する。
 * 書き込みはしない。結果は判定する人で変わるので、Cache-Control: private, no-store を付ける。
 */
class RecordCandidatesController extends Controller
{
    public function __construct(private RecordCandidatesQuery $candidates) {}

    public function __invoke(RecordCandidatesRequest $request, string $module): JsonResponse
    {
        $context = AccessContext::of($request);
        $records = $this->candidates->candidates(Requester::fromAccessContext($context), $module, $request->keyword(), $request->excludedId(), $context->date);

        return response()->json(['records' => $records])->header('Cache-Control', 'private, no-store');
    }
}
