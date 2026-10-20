<?php

namespace App\Http\Controllers\Web\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SearchRequest;
use App\Queries\Master\MasterSearchQuery;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;

/**
 * 共通マスタの検索の口(docs の 設計/共通マスタ/検索と組織図.md 2 節、docs の 設計/adr/0006): ヘッダーの検索欄が、ページ遷移なしに候補の
 * ユーザーと組織を JSON で取る。ルート単位の認可(AuthorizeRoute)と FormRequest を通り、書き込みはしない。結果は判定する人・基準日・
 * ロケールで変わるので、Cache-Control: private, no-store を付ける。基準日・ロケールは {@see AccessContext} から取る。
 */
class SearchController extends Controller
{
    public function __construct(private MasterSearchQuery $search) {}

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $context = AccessContext::of($request);

        return response()
            ->json($this->search->search($request->keyword(), $context->date, $context->localeCode))
            ->header('Cache-Control', 'private, no-store');
    }
}
