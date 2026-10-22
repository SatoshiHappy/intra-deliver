<?php

namespace App\Queries\Meeting;

use App\Queries\Ai\Api\RunQuery;
use App\Queries\Ai\Api\TranscriptionFormats;
use App\Queries\Records\Api\RecordAttachmentQuery;
use Generator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 会議の音声の添付の読み取り(会議の今の添付 = 入れ物の添付の読み取りの口、受ける形式 = 文字起こしの形式の口、実行の入力の音声 = AI の実行の読み取りの口。
 * docs の 設計/業務ツール/会議/処理.md の 3.2・3.3・3.7)。主体の判定をしない(呼び手が会議の閲覧を判定した後か、完了の受け手の中で呼ぶ)。
 *
 * @phpstan-import-type AttachmentItem from RecordAttachmentQuery
 */
class MeetingAudioQuery
{
    /** 添付を読む 1 回の数 */
    private const PAGE_SIZE = 100;

    public function __construct(
        private RecordAttachmentQuery $attachments,
        private TranscriptionFormats $formats,
        private RunQuery $runs,
    ) {}

    /**
     * 実体が会議の今の添付か。
     *
     * @throws ModelNotFoundException 会議が無いとき
     */
    public function isAttached(int $recordId, int $uploadId): bool
    {
        return $this->attachmentOf($recordId, $uploadId) !== null;
    }

    /**
     * 実体を付けた会議の今の添付の名前(外されていれば null)。
     *
     * @throws ModelNotFoundException 会議が無いとき
     */
    public function nameOf(int $recordId, int $uploadId): ?string
    {
        return $this->attachmentOf($recordId, $uploadId)['name'] ?? null;
    }

    /**
     * 会議の今の添付のうち、文字起こしが受ける形式で実体のある音声のうち最も新しく付けたものの実体(無ければ null)。
     *
     * @throws ModelNotFoundException 会議が無いとき
     */
    public function latestAudioOf(int $recordId): ?int
    {
        foreach ($this->items($recordId) as $item) {
            if ($item['purgeDueOn'] === null && $this->formats->accepts($item['name'], $item['mime'])) {
                return $item['uploadId'];
            }
        }

        return null;
    }

    /**
     * 文字起こしの実行の入力の音声の実体(音声の無い実行・台帳の行が消された実行は null)。
     */
    public function inputUploadOf(int $runId): ?int
    {
        return $this->runs->inputUploadIdsOf([$runId])[$runId] ?? null;
    }

    /**
     * @return AttachmentItem|null
     *
     * @throws ModelNotFoundException
     */
    private function attachmentOf(int $recordId, int $uploadId): ?array
    {
        foreach ($this->items($recordId) as $item) {
            if ($item['uploadId'] === $uploadId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 会議の今の添付(新しい順)を、決まった数ずつ読んで 1 つずつ返す。
     *
     * @return Generator<int, AttachmentItem>
     *
     * @throws ModelNotFoundException
     */
    private function items(int $recordId): Generator
    {
        $beforeId = null;

        do {
            $page = $this->attachments->forRecord($recordId, self::PAGE_SIZE, $beforeId);

            foreach ($page['items'] as $item) {
                yield $item;
                $beforeId = $item['id'];
            }
        } while ($page['hasMore']);
    }
}
