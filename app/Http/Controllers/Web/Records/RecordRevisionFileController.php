<?php

namespace App\Http\Controllers\Web\Records;

use App\Http\Controllers\Controller;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Records\RecordViewGate;
use App\Queries\Upload\Api\UploadResponses;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 外した時点のファイル(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.4 節): 閲覧の判定({@see RecordViewGate})の後に、
 * そのレコードの版の写しにある実体だけを返す(版に無い upload_id は 404。名前はその実体を含む最新の版の名前)。
 */
class RecordRevisionFileController extends Controller
{
    public function __construct(
        private RecordViewGate $views,
        private RecordAttachmentFiles $files,
        private UploadResponses $responses,
    ) {}

    public function show(Request $request, string $module, string $record, string $upload): BinaryFileResponse
    {
        $file = $this->opened($request, $module, (int) $record, (int) $upload);

        return $this->responses->download($file['uploadId'], $file['name']);
    }

    public function preview(Request $request, string $module, string $record, string $upload): BinaryFileResponse
    {
        return $this->responses->preview($this->opened($request, $module, (int) $record, (int) $upload)['uploadId']);
    }

    /**
     * @return array{uploadId: int, name: string}
     */
    private function opened(Request $request, string $module, int $recordId, int $uploadId): array
    {
        $context = AccessContext::of($request);
        $this->views->authorize(Requester::fromAccessContext($context), $module, $recordId, $context->date);

        return $this->files->revisionFile($recordId, $uploadId);
    }
}
