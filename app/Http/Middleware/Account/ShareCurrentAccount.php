<?php

namespace App\Http\Middleware\Account;

use App\Queries\Account\Api\UserMenuSections;
use App\Queries\Account\CurrentAccountQuery;
use App\Support\AccessContext;
use Closure;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 今の利用者を共有プロパティ account で、利用者メニューの節を共有プロパティ userMenuSections で全ページに渡す
 * (docs の 設計/デモユーザー切替/今の利用者.md 5.7)。起動の配線が routes/web.php のグループに、グローバルナビの共有の後に付ける。
 *
 * どちらもクロージャを always の props にする(部分再読み込みでも毎回作って返す。利用者は要求ごとに変わりうる)。
 * 節は、タグ {@see UserMenuSections::TAG} で登録した実装を登録の順に呼んだ一覧で、登録が無ければ空の一覧。
 * 判定の入力は {@see AccessContext} から作る。
 */
class ShareCurrentAccount
{
    /**
     * @param  iterable<UserMenuSections>  $userMenuSections
     */
    public function __construct(
        private CurrentAccountQuery $accounts,
        #[Tag(UserMenuSections::TAG)] private iterable $userMenuSections,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('account', Inertia::always(fn () => $this->accounts->currentAccountOf(AccessContext::of($request))));
        Inertia::share('userMenuSections', Inertia::always(fn () => $this->sectionsOf(AccessContext::of($request))));

        return $next($request);
    }

    /**
     * @return list<array{heading: string, items: list<array{code: string, name: string|null, note: string|null, url: string, method: 'get'|'post', data: array<string, int|string>}>}>
     */
    private function sectionsOf(AccessContext $context): array
    {
        $sections = [];

        foreach ($this->userMenuSections as $userMenuSections) {
            $sections[] = $userMenuSections->section($context);
        }

        return $sections;
    }
}
