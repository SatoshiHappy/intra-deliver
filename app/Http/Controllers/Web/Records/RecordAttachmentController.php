<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\DetachRecordAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordAttachmentRequest;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Records\RecordViewGate;
use App\Queries\Upload\Api\UploadResponses;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * レコードの添付のルート(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 8 節): 追加・外すは
 * 業務モジュールの update(業務処理が判定)で元の画面へ 303、ダウンロード・プレビューは閲覧の判定({@see RecordViewGate})の後に、
 * そのレコードの今の添付の実体を返す(ほかのレコードの添付は 404)。持ち主の鍵は HTTP から来ない(追加はいつも鍵 null)。
 */
class RecordAttachmentController extends Controller
{
    public function __construct(
        private AttachRecordUploads $attach,
        private DetachRecordAttachments $detach,
        private RecordViewGate $views,
        private RecordAttachmentFiles $files,
        private UploadResponses $responses,
    ) {}

    public function store(RecordAttachmentRequest $request, string $module, string $record): RedirectResponse
    {
        $this->attach->execute(AccessContext::of($request), $module, (int) $record, $request->attachmentFiles());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'ファイルを追加しました']);

        return redirect()->back(303);
    }

    public function destroy(Request $request, string $module, string $record, string $attachment): RedirectResponse
    {
        $this->detach->execute(AccessContext::of($request), $module, (int) $record, (int) $attachment);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'ファイルを削除しました']);

        return redirect()->back(303);
    }

    public function show(Request $request, string $module, string $record, string $attachment): BinaryFileResponse
    {
        $file = $this->opened($request, $module, (int) $record, (int) $attachment);

        return $this->responses->download($file['uploadId'], $file['name']);
    }

    public function preview(Request $request, string $module, string $record, string $attachment): BinaryFileResponse
    {
        $file = $this->opened($request, $module, (int) $record, (int) $attachment);

        return $this->responses->preview($file['uploadId']);
    }

    /**
     * @return array{uploadId: int, name: string}
     */
    private function opened(Request $request, string $module, int $recordId, int $attachmentId): array
    {
        $context = AccessContext::of($request);
        $found = $this->views->authorize(Requester::fromAccessContext($context), $module, $recordId, $context->date);

        return $this->files->attachment($found->id, $recordId, $attachmentId);
    }
}
