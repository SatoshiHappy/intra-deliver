<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRevisionComparison;
use App\Queries\Records\RecordRevisionCurrentFields;
use App\Queries\Records\RecordRevisionDiff;
use App\Queries\Records\RecordRevisionRowAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 更新履歴の版の読み取りの公開の口(主体・認可・ロック・結果・失敗の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の表)。
 * ワークフローの申請・再申請の履歴の行が版を指す(同じ文書の 4.2 節)ための口と、処理履歴の差分の口。
 *
 * 差分の形: fields = 変わった項目(後の版の項目の並び。名前は後の版の定義の写し)、details = 行の変わった明細の項目(行は追加・削除・変更の順)、
 * attachments = 足した・外した添付(追加・削除の順)。値は画面の値の形(設計/業務データの入れ物/画面/README.md の 1.2 節の RecordFieldValue)。
 *
 * @phpstan-import-type RevisionCopy from RecordRevisionComparison
 * @phpstan-import-type ShownRowAccessChange from RecordRevisionRowAccess
 *
 * @phpstan-type RowChange array{change: 'added'|'removed'|'changed', rowId: int, before: array<string, mixed>|null,
 *     after: array<string, mixed>|null, changedCodes: list<string>}
 * @phpstan-type AttachmentChange array{change: 'added'|'removed', id: int, uploadId: int|null, name: string}
 * @phpstan-type Diff array{fields: list<array{code: string, name: string, before: mixed, after: mixed}>,
 *     details: list<array{code: string, name: string, rows: list<RowChange>}>, attachments: list<AttachmentChange>,
 *     rowAccess: ShownRowAccessChange|null}
 */
class RecordRevisionQuery
{
    public function __construct(
        private RecordQuery $records,
        private RecordDefinitionQuery $definitions,
        private RecordRevisionComparison $comparison,
        private RecordRevisionCurrentFields $currentFields,
        private RecordRevisionDiff $differ,
    ) {}

    /**
     * 判定をしない・鍵を取らない(呼び手が書いた直後に、同じトランザクションで、行の鍵を持ったまま呼ぶ)。
     *
     * @return int|null そのレコードの最新の版の id(版が無ければ null)
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function latestId(int $recordId): ?int
    {
        $record = $this->records->topLevel($recordId);
        $id = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * 業務モジュールの read を見ない(呼び手が閲覧を判定してから呼ぶ)。参照の相手の表示名だけ、判定を求める者で出し分ける。
     *
     * @return Diff 前の版から後の版への差分
     *
     * @throws ModelNotFoundException<RecordRevision> 版が無い・2 つの版が別のレコードの版のとき
     */
    public function diffBetween(
        Requester $requester,
        int $beforeRevisionId,
        int $afterRevisionId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        [$before, $after] = $this->copies($beforeRevisionId, $afterRevisionId);

        return $this->differ->diff($before, $after, $requester, $date, $localeCode);
    }

    /**
     * {@see self::diffBetween()} の差分を、今の定義で描ける項目(後の版の写しの項目と同じ項目が今の定義にあるもの)だけに絞る
     * (コードで今の定義を引いて描く処理履歴の差分のため。処理履歴の差分と一緒に消す)。判定と失敗は diffBetween と同じ。
     *
     * @return Diff
     *
     * @throws ModelNotFoundException<RecordRevision|RecordModule> 版が無い・2 つの版が別のレコードの版のとき
     */
    public function diffOnCurrentDefinition(
        Requester $requester,
        int $beforeRevisionId,
        int $afterRevisionId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        [$before, $after, $moduleId] = $this->copies($beforeRevisionId, $afterRevisionId);
        $module = $this->definitions->load(RecordModule::query()->findOrFail($moduleId));
        $changes = $this->currentFields->filter($this->comparison->compare($before, $after), $module);

        return $this->differ->shown($changes, $before, $after, $requester, $date, $localeCode);
    }

    /**
     * 同じレコードの版 2 つの写しと、その業務モジュールの id。
     *
     * @return array{RevisionCopy, RevisionCopy, int}
     *
     * @throws ModelNotFoundException<RecordRevision> 版が無い・2 つの版が別のレコードの版のとき
     */
    private function copies(int $beforeRevisionId, int $afterRevisionId): array
    {
        $before = RecordRevision::query()->findOrFail($beforeRevisionId);
        $after = RecordRevision::query()->findOrFail($afterRevisionId);

        if ($before->record_id !== $after->record_id) {
            throw (new ModelNotFoundException)->setModel(RecordRevision::class, [$beforeRevisionId]);
        }

        return [
            ['snapshot' => $before->snapshot ?? [], 'labels' => $before->labels ?? []],
            ['snapshot' => $after->snapshot ?? [], 'labels' => $after->labels ?? []],
            $after->record_module_id,
        ];
    }
}
