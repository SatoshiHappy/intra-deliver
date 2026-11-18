<?php

namespace App\Http\Controllers\Web\Notice;

use App\Actions\Notice\UpdateNoticeSetting;
use App\Http\Controllers\Controller;
use App\Queries\Notice\NoticeSettingsQuery;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 利用者の画面「通知の設定」の入口(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3 節、
 * ルートと文言と検査.md の 5 節): 画面 Notice/Settings と、種類 1 つのミュートするかの保存(入力 type・muted。確かめは業務処理)。
 * 保存は元の画面へ 303 で戻る(画面は部分再読み込みで行を作り直す)。
 */
class NoticeSettingController extends Controller
{
    public function __construct(
        private NoticeSettingsQuery $settings,
        private UpdateNoticeSetting $updateNoticeSetting,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Notice/Settings', $this->settings->own(AccessContext::of($request)));
    }

    public function update(Request $request): RedirectResponse
    {
        $context = AccessContext::of($request);
        $this->updateNoticeSetting->execute(
            $context,
            $request->input('type'),
            $request->input(UpdateNoticeSetting::VALUE_KEY),
        );

        return redirect()->back(303, fallback: route('notice.settings.show'));
    }
}
