<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Upload\Api\UploadFile;
use App\Queries\Upload\Api\UploadQuery;
use App\Queries\Upload\Api\UploadResponses;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * サイドパネルの「更新履歴」の欄の版の行(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5 節): 新しい版から、頭
 * (日時・操作した人・代理元・契機)と、1 つ前の版との差分({@see RecordRevisionDiff} = 差分の 1 か所)。判定は呼び手がしてから呼ぶ。
 * 差分の項目・明細の項目には、値を描くための後の版の定義の写し(型・複数行・参照先)を添える。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type FieldEntry from RecordRevisionLabels
 * @phpstan-import-type RowChange from RecordRevisionQuery
 * @phpstan-import-type Diff from RecordRevisionQuery
 *
 * @phpstan-type RevisionField array{code: string, name: string, type: string, isMultiline: bool,
 *     referencedModule: array{code: string}|null}
 * @phpstan-type RevisionAttachmentChange array{change: 'added'|'removed', id: int, name: string, uploadId: int|null,
 *     mime: string|null, previewable: bool, purgeDueOn: string|null}
 * @phpstan-type RevisionChanges array{
 *     fields: list<array{code: string, name: string, field: RevisionField, before: mixed, after: mixed}>,
 *     details: list<array{code: string, name: string, fields: list<RevisionField>, rows: list<RowChange>}>,
 *     attachments: list<RevisionAttachmentChange>}
 * @phpstan-type RevisionRow array{id: int, revision: int, at: string|null, action: 'create'|'update',
 *     actor: ReferenceValue|null, principal: ReferenceValue|null,
 *     origin: array{operation: string, nodeName: string|null}|null, noPreviousRecord: bool,
 *     changes: RevisionChanges|null}
 */
class RecordRevisionRows
{
    public function __construct(
        private RecordRevisionDiff $differ,
        private RecordRevisionLabels $labels,
        private RecordValueReader $reader,
        private UploadQuery $uploads,
        private UploadResponses $responses,
    ) {}

    /**
     * @param  int|null  $beforeId  続きを読むとき、前の結果の最後の版の id(それより古い版を返す。ほかのレコードの版なら 0 行)
     * @return array{rows: list<RevisionRow>, hasMore: bool}
     */
    public function page(
        Requester $requester,
        int $recordId,
        int $limit,
        ?int $beforeId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $before = RecordRevision::query()->select('revision')->where('record_id', $recordId)->whereKey($beforeId);
        // 1 つ多く読み、続きの有無と、最後の行の 1 つ前の版に使う
        $revisions = RecordRevision::query()->where('record_id', $recordId)
            ->when($beforeId !== null, fn ($query) => $query->where('revision', '<', $before))
            ->orderByDesc('revision')->limit($limit + 1)->get()->values();
        $users = $this->reader->users($this->userIds($revisions), $date, $localeCode);
        $rows = [];

        foreach ($revisions->take($limit) as $index => $revision) {
            $previous = $revisions->get($index + 1);
            $rows[] = $this->row($revision, $previous, $users, $requester, $date, $localeCode);
        }

        return ['rows' => $this->withFiles($rows), 'hasMore' => $revisions->count() > $limit];
    }

    /**
     * @param  array<int, ReferenceValue>  $users
     * @return RevisionRow
     */
    private function row(
        RecordRevision $revision,
        ?RecordRevision $previous,
        array $users,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $isUpdate = $revision->action === RecordRevisionAction::Update;
        $diff = $isUpdate && $previous !== null
            ? $this->differ->diff($this->copy($previous), $this->copy($revision), $requester, $date, $localeCode)
            : null;

        return [
            'id' => $revision->id,
            'revision' => $revision->revision,
            'at' => $revision->created_at?->toIso8601String(),
            'action' => $isUpdate ? 'update' : 'create',
            'actor' => $revision->user_id === null ? null : $users[$revision->user_id] ?? null,
            'principal' => $revision->principal_user_id === null ? null : $users[$revision->principal_user_id] ?? null,
            'origin' => $revision->origin_operation === null
                ? null
                : ['operation' => $revision->origin_operation, 'nodeName' => $revision->origin_node_name],
            'noPreviousRecord' => $isUpdate && $previous === null,
            'changes' => $diff === null || $this->isEmpty($diff) ? null : $this->changes($diff, $revision),
        ];
    }

    /**
     * @param  Diff  $diff
     * @return RevisionChanges
     */
    private function changes(array $diff, RecordRevision $revision): array
    {
        $entries = [];

        foreach ($this->labels->entries(($revision->labels ?? [])['fields'] ?? []) as $entry) {
            $entries[$entry['code']] = $entry;
        }

        return [
            'fields' => array_map(fn (array $change) => [
                ...$change, 'field' => $this->field($entries[$change['code']]),
            ], $diff['fields']),
            'details' => array_map(fn (array $detail) => [
                'code' => $detail['code'],
                'name' => $detail['name'],
                'fields' => array_map(
                    fn (array $entry) => $this->field($entry),
                    $this->labels->entries($entries[$detail['code']]['children']),
                ),
                'rows' => $detail['rows'],
            ], $diff['details']),
            'attachments' => array_map(fn (array $change) => [
                'change' => $change['change'],
                'id' => $change['id'],
                'name' => $change['name'],
                // 開けるのは外した行だけ(追加の行は添付ファイルの欄から開く = 更新履歴.md の 5.2 節)
                'uploadId' => $change['change'] === 'removed' ? $change['uploadId'] : null,
                'mime' => null,
                'previewable' => false,
                'purgeDueOn' => null,
            ], $diff['attachments']),
        ];
    }

    /**
     * 外した行の実体の型・プレビューできるか・期限で消した日を台帳から足す(台帳の行が無い・期限で消した実体は開けない)。
     *
     * @param  list<RevisionRow>  $rows
     * @return list<RevisionRow>
     */
    private function withFiles(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids = [...$ids, ...array_filter(array_column($row['changes']['attachments'] ?? [], 'uploadId'))];
        }

        $files = $ids === [] ? [] : $this->uploads->find(array_values(array_unique($ids)));

        return array_map(function (array $row) use ($files) {
            if ($row['changes'] !== null) {
                $row['changes']['attachments'] = array_map(
                    fn (array $change) => $this->withFile($change, $files),
                    $row['changes']['attachments'],
                );
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  RevisionAttachmentChange  $change
     * @param  array<int, UploadFile>  $files
     * @return RevisionAttachmentChange
     */
    private function withFile(array $change, array $files): array
    {
        $file = $change['uploadId'] === null ? null : $files[$change['uploadId']] ?? null;

        if ($file === null) {
            return [...$change, 'uploadId' => null];
        }

        $kept = $file->purgeDueOn === null;

        return [
            ...$change,
            'uploadId' => $kept ? $file->id : null,
            'mime' => $file->mime,
            'previewable' => $kept && $this->responses->isPreviewable($file->mime),
            'purgeDueOn' => $file->purgeDueOn,
        ];
    }

    /**
     * @param  FieldEntry  $entry
     * @return RevisionField
     */
    private function field(array $entry): array
    {
        return [
            'code' => $entry['code'],
            'name' => $entry['name'],
            'type' => $entry['type']->value,
            'isMultiline' => $entry['multiline'],
            'referencedModule' => $entry['referencedModule'] === null ? null : ['code' => $entry['referencedModule']],
        ];
    }

    /**
     * @param  Diff  $diff
     */
    private function isEmpty(array $diff): bool
    {
        return $diff['fields'] === [] && $diff['details'] === [] && $diff['attachments'] === [];
    }

    /**
     * @return array{snapshot: array<array-key, mixed>, labels: array<array-key, mixed>}
     */
    private function copy(RecordRevision $revision): array
    {
        return ['snapshot' => $revision->snapshot ?? [], 'labels' => $revision->labels ?? []];
    }

    /**
     * @param  Collection<int, RecordRevision>  $revisions
     * @return list<int>
     */
    private function userIds(Collection $revisions): array
    {
        $ids = $revisions->flatMap(fn (RecordRevision $revision) => [$revision->user_id, $revision->principal_user_id]);

        return array_values(array_unique(array_filter($ids->all(), 'is_int')));
    }
}
