<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Queries\Upload\Api\UploadFile;
use App\Queries\Upload\Api\UploadQuery;
use App\Queries\Upload\Api\UploadResponses;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 更新履歴の版の行(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5 節)。サイドパネルの欄と公開の口が同じ行の中身に
 * なるよう、行を 1 か所で作る(差分は {@see RecordRevisionDiff})。判定は呼び手がしてから呼ぶ。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type RowChange from RecordRevisionDiff
 * @phpstan-import-type Diff from RecordRevisionDiff
 * @phpstan-import-type ShownRowAccessChange from RecordRevisionRowAccess
 * @phpstan-import-type RevisionField from RecordRevisionLabels
 *
 * @phpstan-type RevisionAttachmentChange array{change: 'added'|'removed', id: int, name: string, uploadId: int|null,
 *     mime: string|null, previewable: bool, purgeDueOn: string|null,
 *     downloadUrl?: string|null, previewUrl?: string|null}
 * @phpstan-type RevisionChanges array{
 *     fields: list<array{code: string, name: string, field: RevisionField, before: mixed, after: mixed}>,
 *     details: list<array{code: string, name: string, fields: list<RevisionField>, rows: list<RowChange>}>,
 *     attachments: list<RevisionAttachmentChange>, rowAccess: ShownRowAccessChange|null}
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
        $revisions = $this->revisionsOf($recordId)
            ->when($beforeId !== null, fn ($query) => $query->where('revision', '<', $before))
            ->limit($limit + 1)->get()->values();

        return [
            'rows' => $this->rows($revisions, $limit, $requester, $date, $localeCode),
            'hasMore' => $revisions->count() > $limit,
        ];
    }

    /**
     * ページの番号で読む(公開の口 {@see RecordActivityQuery::revisions()} の中身。保証 = docs の
     * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3)。判定とレコードの有無は呼び手が確かめてから呼ぶ。
     *
     * @param  int<1, max>  $perPage
     * @return array{rows: list<RevisionRow>, total: int, currentPage: int, lastPage: int}
     */
    public function numberedPage(
        Requester $requester,
        int $recordId,
        int $page,
        int $perPage,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $total = $this->revisionsOf($recordId)->count();
        $current = PageNumbers::clamp($page, $total, $perPage);
        // 1 つ多く読み、最後の行の 1 つ前の版に使う(続きの読み方と同じ)。件数を超える行は無いので、件数で抑えてあふれさせない
        $revisions = $this->revisionsOf($recordId)
            ->offset(($current - 1) * $perPage)->limit(min($perPage, $total) + 1)->get()->values();

        return [
            'rows' => $this->rows($revisions, $perPage, $requester, $date, $localeCode),
            'total' => $total,
            'currentPage' => $current,
            'lastPage' => PageNumbers::lastPage($total, $perPage),
        ];
    }

    /**
     * @return Builder<RecordRevision>
     */
    private function revisionsOf(int $recordId): Builder
    {
        return RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision');
    }

    /**
     * 先頭の $limit 件の行(1 つ多く読んだ版を、最後の行の差分の前の版に使う)。
     *
     * @param  Collection<int, RecordRevision>  $revisions
     * @return list<RevisionRow>
     */
    private function rows(
        Collection $revisions,
        int $limit,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $users = $this->reader->users($this->userIds($revisions), $date, $localeCode);
        $rows = [];

        foreach ($revisions->take($limit) as $index => $revision) {
            $previous = $revisions->get($index + 1);
            $rows[] = $this->row($revision, $previous, $users, $requester, $date, $localeCode);
        }

        return $this->withFiles($rows);
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
        $after = RecordRevisionDiff::snapshotOf($revision);
        $diff = $isUpdate && $previous !== null
            ? $this->differ->diff(RecordRevisionDiff::snapshotOf($previous), $after, $requester, $date, $localeCode)
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
                ...$change, 'field' => RecordRevisionLabels::fieldOf($entries[$change['code']]),
            ], $diff['fields']),
            'details' => array_map(fn (array $detail) => [
                'code' => $detail['code'],
                'name' => $detail['name'],
                'fields' => array_map(
                    RecordRevisionLabels::fieldOf(...),
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
            'rowAccess' => $diff['rowAccess'],
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
     * @param  Diff  $diff
     */
    private function isEmpty(array $diff): bool
    {
        return $diff['fields'] === [] && $diff['details'] === [] && $diff['attachments'] === []
            && $diff['rowAccess'] === null;
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
