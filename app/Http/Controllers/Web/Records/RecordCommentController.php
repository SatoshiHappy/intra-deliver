<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\DeleteRecordComment;
use App\Actions\Records\PostRecordComment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordCommentRequest;
use App\Http\Requests\Records\RecordMentionCandidatesRequest;
use App\Queries\Records\RecordMentionCandidates;
use App\Support\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * レコードのコメントのルート(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 6 節): 投稿・自分の分の削除は元の画面へ 303
 * (`back()`。レコードの詳細と申請の詳細が同じルートを使う = 詳細の画面/README.md の 3 節)、宛先の候補は JSON。
 * 判定は閲覧の判定とユーザー(投稿・候補は FormRequest が、削除は業務処理が確かめる)。
 */
class RecordCommentController extends Controller
{
    public function __construct(
        private PostRecordComment $post,
        private DeleteRecordComment $delete,
        private RecordMentionCandidates $candidates,
    ) {}

    public function store(RecordCommentRequest $request, string $module, string $record): RedirectResponse
    {
        $this->post->execute(
            AccessContext::of($request),
            $module,
            (int) $record,
            $request->commentBody(),
            $request->mentionUserIds(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'コメントを投稿しました']);

        return redirect()->back(303);
    }

    public function destroy(Request $request, string $module, string $record, string $comment): RedirectResponse
    {
        $this->delete->byAuthor(AccessContext::of($request), $module, (int) $record, (int) $comment);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'コメントを削除しました']);

        return redirect()->back(303);
    }

    /**
     * 結果は判定する人で変わるので、Cache-Control: private, no-store を付ける。
     */
    public function candidates(RecordMentionCandidatesRequest $request, string $module, string $record): JsonResponse
    {
        $context = AccessContext::of($request);
        $rows = $this->candidates->candidates(
            $module,
            (int) $record,
            $request->keyword(),
            $context->date,
            $context->localeCode,
        );

        return response()->json($rows)->header('Cache-Control', 'private, no-store');
    }
}
