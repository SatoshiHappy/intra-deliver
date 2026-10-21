<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Records\RecordAttachment;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 添付を受けるワークフローの口へ、画面と同じ multipart の形(envelope + attachments[] = docs の
 * 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節)で送るテストの手助け。要求は Inertia の要求で、戻り先は referer。
 */
trait SendsAttachmentEnvelopes
{
    /**
     * envelope に入力の JSON、attachments にファイルを入れて送る。
     *
     * @param  array<string, mixed>  $input
     * @param  array<array-key, mixed>|UploadedFile  $files
     * @return TestResponse<Response>
     */
    private function multipart(
        string $method,
        string $uri,
        array $input,
        array|UploadedFile $files = [],
        string $from = '/',
    ): TestResponse {
        // 画面は入力をオブジェクトとして送るので、空の入力も {} にする
        $fields = ['envelope' => json_encode((object) $input, JSON_THROW_ON_ERROR)];

        return $this->multipartFields($method, $uri, $fields, $files, $from);
    }

    /**
     * multipart の欄をそのまま送る(envelope の壊れた形を送るため)。
     *
     * @param  array<string, mixed>  $fields
     * @param  array<array-key, mixed>|UploadedFile  $files
     * @return TestResponse<Response>
     */
    private function multipartFields(
        string $method,
        string $uri,
        array $fields,
        array|UploadedFile $files = [],
        string $from = '/',
    ): TestResponse {
        $server = [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            'HTTP_X_INERTIA' => 'true',
            'HTTP_REFERER' => $from,
        ];

        return $this->call($method, $uri, $fields, [], ['attachments' => $files], $server);
    }

    /**
     * セッションに戻った誤りのキー(既定の誤りの袋。並びは名前の順)。
     *
     * @return list<string>
     */
    private function sessionErrorKeys(): array
    {
        $errors = session('errors');
        $keys = $errors instanceof ViewErrorBag ? $errors->getBag('default')->keys() : [];
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string> 案件のレコードの添付の名前(付けた順)
     */
    private function recordAttachmentNames(int $caseId): array
    {
        $recordId = WorkflowCase::query()->findOrFail($caseId)->record_id;

        $names = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->pluck('name');

        return array_values($names->all());
    }

    /**
     * @return list<string> 一時保存の添付の名前(付けた順)
     */
    private function draftAttachmentNames(int $caseId): array
    {
        $names = WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $caseId)->orderBy('id')->pluck('name');

        return array_values($names->all());
    }
}
