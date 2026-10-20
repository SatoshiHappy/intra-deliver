<?php

namespace App\Http\Controllers\Web\Notice;

use App\Actions\Notice\MarkAllNoticesRead;
use App\Actions\Notice\MarkNoticeRead;
use App\Http\Controllers\Controller;
use App\Queries\Notice\NoticeList;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 通知のモジュールの入口(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節): 通知の一覧 Notice/Index(クエリ filter・page は読み取りが解釈する)、
 * 鈴の中の最新 10 件の JSON(ゲストは空。利用者ごとの値なので Cache-Control: private, no-store)、
 * 行を押した時(既読にしてから移り先を判定し直す = 同じ文書の 5 節の「押した時」)、すべて既読にする(元の画面へ戻る)。
 */
class NoticeController extends Controller
{
    public function __construct(
        private NoticeList $notices,
        private MarkNoticeRead $markNoticeRead,
        private MarkAllNoticesRead $markAllNoticesRead,
    ) {}

    public function index(Request $request): Response
    {
        $page = $this->notices->page(AccessContext::of($request), $request->query('filter'), $request->query('page'));

        return Inertia::render('Notice/Index', $page);
    }

    public function latest(Request $request): JsonResponse
    {
        return response()
            ->json(['rows' => $this->notices->latest(AccessContext::of($request))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notice): RedirectResponse
    {
        $context = AccessContext::of($request);
        $this->markNoticeRead->execute($context, (int) $notice);
        $destination = $this->notices->destination($context, (int) $notice);

        if ($destination['url'] !== null) {
            return redirect()->to($destination['url']);
        }

        if ($destination['unavailableText'] !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $destination['unavailableText']]);
        }

        return redirect()->back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->markAllNoticesRead->execute(AccessContext::of($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'すべて既読にしました']);

        return redirect()->back();
    }
}
