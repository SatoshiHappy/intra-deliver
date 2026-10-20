<?php

namespace App\Http\Middleware\Notice;

use App\Queries\Notice\NoticeList;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 通知を共有プロパティで全ページに渡す(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節)。起動の配線が routes/web.php のグループに、
 * ルートの認可とグローバルナビの共有の後に付ける。名前はページの props と重ねない(ページの props が共有の上に重なるため)。
 *
 * noticeSummary = 未読の数(ゲストは null)。always にして部分再読み込みでも新しくする。
 * 鈴の中の最新 10 件は共有しない(毎回の画面で引かない。鈴を開いた時に notice.latest の JSON で引く)。
 */
class ShareNotices
{
    public function __construct(private NoticeList $notices) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('noticeSummary', Inertia::always(fn () => $this->notices->summary(AccessContext::of($request))));

        return $next($request);
    }
}
