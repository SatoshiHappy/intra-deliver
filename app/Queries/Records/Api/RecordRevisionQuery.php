<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRevisionLabels;
use App\Queries\Records\RecordRevisionViews;
use App\Queries\Records\RecordValueReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 更新履歴の版の読み取りの公開の口(主体・認可・ロック・結果・失敗の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の表)。
 * 申請の詳細が使う口と写しの形は 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節と
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節・4.2 節・6 節。
 *
 * @phpstan-import-type RevisionField from RecordRevisionLabels
 * @phpstan-import-type PartyValue from RecordValueReader
 *
 * @phpstan-type RevisionView array{groups: list<array{id: int|null, name: string|null, fields: list<RevisionField>}>,
 *     values: array<string, mixed>,
 *     details: list<array{code: string, name: string, fields: list<RevisionField>,
 *         rows: list<array{id: int, values: array<string, mixed>}>}>,
 *     rowAccess: array{restricted: bool, viewers: list<PartyValue>}|null}
 */
class RecordRevisionQuery
{
    public function __construct(
        private RecordQuery $records,
        private RecordRevisionViews $views,
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
     * 判定をしない(呼び手が閲覧を判定済み)。
     *
     * @return bool 同じレコードに、その版より後の版があるか
     *
     * @throws ModelNotFoundException<RecordRevision> 版が無いとき
     */
    public function hasRevisionAfter(int $revisionId): bool
    {
        $revision = RecordRevision::query()->findOrFail($revisionId);

        return RecordRevision::query()->where('record_id', $revision->record_id)
            ->where('revision', '>', $revision->revision)->exists();
    }

    /**
     * 判定をしない(呼び手 = ワークフローが申請の閲覧と完了を判定し、その申請の履歴の行が指す版を渡す)。参照の相手の表示名だけ、判定を求める者で
     * 出し分ける。
     *
     * @return RevisionView 版の写しを今の見出しのまとまりに入れた詳細の形(添付は含めない)
     *
     * @throws ModelNotFoundException<RecordRevision> 版が無いとき
     */
    public function view(Requester $requester, int $revisionId, DateTimeInterface $date, string $localeCode): array
    {
        return $this->views->of(RecordRevision::query()->findOrFail($revisionId), $requester, $date, $localeCode);
    }
}
