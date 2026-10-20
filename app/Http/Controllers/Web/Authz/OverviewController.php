<?php

namespace App\Http\Controllers\Web\Authz;

use App\Http\Controllers\Controller;
use App\Queries\Authz\AuthzEditPermissions;
use App\Queries\Authz\JudgeQuery;
use App\Queries\Authz\JudgeRequesters;
use App\Queries\Authz\OverviewQuery;
use App\Support\AccessContext;
use App\Support\QueryParameters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 認可設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1 節): ページ Authz/Overview。読み取り専用。
 * リソースの種類はクエリ type で選び(読み方は {@see OverviewQuery::resourceTypeOf()})、選んだ種類の格子 grid だけを作る。
 * 判定する人はクエリ user で選ぶ(読み方は {@see JudgeQuery::judgeUserIdOf()})。選んだ人とその主所属で判定し({@see JudgeRequesters::forUserIn()})、
 * 基準日・ロケール・接続元は {@see AccessContext} のもの。props はどれもクロージャで渡し、部分再読み込み(判定する人 = grid・judge、
 * 種類 = grid)では種類の選択肢 resourceTypes と候補 candidates を作らない。開けるのはシステム管理者のロールを持つ人だけ(ルートの認可)。
 * can = 操作している人が行える編集の操作({@see AuthzEditPermissions}。セルの切替の後の部分再読み込みでも取り直す)。列の件数と指紋は、
 * 列を削除できる人にだけ grid に入れる(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.3)。
 */
class OverviewController extends Controller
{
    public function __construct(
        private OverviewQuery $overview,
        private JudgeQuery $judges,
        private AuthzEditPermissions $permissions,
    ) {}

    public function __invoke(Request $request): Response
    {
        $context = AccessContext::of($request);
        $userId = $this->judges->judgeUserIdOf(QueryParameters::string($request, 'user'), $context->userId, $context->date);
        $resourceType = $this->overview->resourceTypeOf(QueryParameters::string($request, 'type'));
        // grid と can が同じ判定を使う。props は必要なときだけ作るので、最初に呼ばれたときに 1 回だけ判定する
        $can = null;
        $canOf = function () use (&$can, $context) {
            return $can ??= $this->permissions->of($context);
        };

        return Inertia::render('Authz/Overview', [
            'resourceTypes' => fn () => $this->overview->resourceTypes(),
            'grid' => fn () => $this->overview->gridOn(
                JudgeRequesters::forUserIn($context, $userId),
                $resourceType,
                $context->date,
                $context->localeCode,
                $canOf()['deleteSubject'],
            ),
            'candidates' => fn () => $this->judges->candidatesOn($context->date, $context->localeCode),
            'judge' => fn () => $this->judges->judgeOn($userId, $context->date, $context->localeCode),
            'can' => $canOf,
        ]);
    }
}
