<?php

namespace App\Http\Controllers\Web\Notice;

use App\Actions\Notice\UpdateRequiredNoticeType;
use App\Http\Controllers\Controller;
use App\Queries\Notice\NoticeSettingsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 管理の画面「通知の管理」の入口(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 4 節、
 * ルートと文言と検査.md の 5 節): 画面 Notice/Admin と、種類 1 つの必須の付け外し(入力 type・value。確かめは業務処理)。
 * 使える人はルートの認可だけで決まるので、判定を求める者を渡さない。保存は元の画面へ 303 で戻る。
 */
class NoticeAdminController extends Controller
{
    public function __construct(
        private NoticeSettingsQuery $settings,
        private UpdateRequiredNoticeType $updateRequiredNoticeType,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Notice/Admin', $this->settings->admin());
    }

    public function update(Request $request): RedirectResponse
    {
        $this->updateRequiredNoticeType->execute($request->input('type'), $request->input('value'));

        return redirect()->back(303, fallback: route('notice.admin.show'));
    }
}
