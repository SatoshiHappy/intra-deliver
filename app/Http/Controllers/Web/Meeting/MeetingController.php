<?php

namespace App\Http\Controllers\Web\Meeting;

use App\Actions\Meeting\CreateMeeting;
use App\Actions\Meeting\RegenerateMinutes;
use App\Enums\Builtin\Meeting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\MeetingRegenerateRequest;
use App\Http\Requests\Meeting\MeetingStoreRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 会議の作成(テキスト・貼り付け)と書き起こしからの再作成の入口(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7)。AI に依頼する業務処理は
 * 操作の引数で受ける。成功は入れ物の業務の一覧・詳細へ戻し、トーストを出す(入れ物の入口と同じ渡し方)。422 は入れ物の入口と同じく前の画面へ戻る。
 */
class MeetingController extends Controller
{
    public const CREATED = '会議を作成しました。完了すると通知します。';

    public const REGENERATION_REQUESTED = '議事録の再作成を依頼しました。完了すると通知します。';

    public function store(MeetingStoreRequest $request, CreateMeeting $createMeeting): RedirectResponse
    {
        $createMeeting->execute(
            AccessContext::of($request),
            $request->importedFile(),
            $request->pastedText(),
            $request->meetingValues(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => self::CREATED]);

        return redirect()->route('records.data.index', ['module' => Meeting::CODE], 303);
    }

    public function regenerate(
        MeetingRegenerateRequest $request,
        string $meeting,
        RegenerateMinutes $regenerateMinutes,
    ): RedirectResponse {
        $regenerateMinutes->execute(AccessContext::of($request), (int) $meeting, $request->instructions());
        Inertia::flash('toast', ['type' => 'success', 'message' => self::REGENERATION_REQUESTED]);

        return redirect()->route('records.data.show', ['module' => Meeting::CODE, 'record' => (int) $meeting], 303);
    }
}
