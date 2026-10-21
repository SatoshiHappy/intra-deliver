<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\DeleteRecordComment;
use App\Http\Controllers\Controller;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * コメントの管理のルート(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 4 節・6 節): ほかの人のコメントを消す。
 * ルートの認可(コメントの管理の権限)だけで通し、業務モジュールの判定をしない(管理の口の規則 = 認可とメニュー/README.md の 2 節)。元の画面へ 303。
 */
class RecordCommentAdminController extends Controller
{
    public function __construct(private DeleteRecordComment $delete) {}

    public function destroy(Request $request, string $comment): RedirectResponse
    {
        $this->delete->byAdministrator(AccessContext::of($request), (int) $comment);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'コメントを削除しました']);

        return redirect()->back(303);
    }
}
