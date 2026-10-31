<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\DepartmentMembers;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Records\Api\RecordAudience;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * 行の権限の判定の 1 か所(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4、ADR 0018・ADR 0026)。
 * 口は 3 つ: 問い合わせに条件を足す {@see self::constrain()} と、1 件の可否 {@see self::allows()} は、判定を求める者と d を受ける。
 * 関係者の式の右辺を満たすユーザーを返す {@see self::relatedUserIds()} は、判定を求める者を受けない(Records の中だけで使う。2.4 の「通知」の宛先の候補)。
 * 業務モジュールの read の判定は呼び手が先に済ませる(2.2)。
 *
 * 所属の組織は Master の公開の口で先に引き、条件は department_id IN (…) にする(Master の表を SQL に書かないため)。
 * 読む時に SQL(EXISTS)で絞るので、変更はその場で効く。参照制限の無いレコードはチェックの列の比較で通し、関係者と参照者の式は
 * 参照制限のあるレコードにだけ掛かる(OR の先頭の列の比較)。
 */
class RecordRowAccess
{
    public function __construct(
        private RecordModuleAuthorization $authorization,
        private DepartmentMembers $members,
        private EffectiveUsers $users,
    ) {}

    /**
     * 問い合わせ(この業務モジュールの親の無いレコード)に、行の判定の条件を足す(設計/業務データの入れ物/認可とメニュー/行の権限.md の
     * 2.4 の「判定」)。業務モジュールの参照制限の既定は登録の初期値だけなので読まない。
     *
     * @param  Builder<Record>  $query
     * @param  RecordModule  $module  親の無い業務モジュール(表から全部の列を読んだもの)
     * @return Builder<Record>
     *
     * @throws LogicException 業務モジュールが親の列を読んでいない・明細のモジュールのとき(作りの誤り)
     */
    public function constrain(
        Builder $query,
        Requester $requester,
        RecordModule $module,
        DateTimeInterface $date,
    ): Builder {
        $this->ensureLoadedTopLevel($module);

        if ($this->authorization->allows($requester, $module, RecordModuleAction::ReadAll, $date)) {
            return $query;
        }

        $userId = $this->users->idsOn(array_filter([$requester->userId]), $date)[0] ?? null;

        if ($userId === null) {
            return $query->where('records.row_access_restricted', false);
        }

        $flagged = $this->flaggedFieldIds($module);
        $departmentIds = $this->members->departmentIdsOn($userId, $date);
        $matches = $this->referenceMatches($userId, $departmentIds);

        return $query->where(fn (Builder $visible) => $visible
            ->where('records.row_access_restricted', false)
            ->orWhere('records.created_by_user_id', $userId)
            ->when($flagged['own'] !== [], fn (Builder $own) => $own
                ->orWhereExists($matches('records', $flagged['own'])))
            ->when($flagged['details'] !== [], fn (Builder $details) => $details->orWhereExists(
                fn (QueryBuilder $rows) => $rows->from('records', 'detail_rows')
                    ->whereColumn('detail_rows.parent_record_module_id', 'records.record_module_id')
                    ->whereColumn('detail_rows.parent_record_id', 'records.id')
                    ->whereExists($matches('detail_rows', $flagged['details'])),
            ))
            ->orWhereExists($this->viewerMatches($userId, $departmentIds)));
    }

    /**
     * レコード(この業務モジュールの親の無いもの)が行の判定を通るか。無いレコードは偽。
     *
     * @param  RecordModule  $module  親の無い業務モジュール(表から全部の列を読んだもの)
     *
     * @throws LogicException 業務モジュールが親の列を読んでいない・明細のモジュールのとき(作りの誤り)
     */
    public function allows(Requester $requester, RecordModule $module, int $recordId, DateTimeInterface $date): bool
    {
        $record = Record::query()->where('record_module_id', $module->id)->whereKey($recordId);

        return $this->constrain($record, $requester, $module, $date)->exists();
    }

    /**
     * 2.4 の「関係者」の式の右辺を満たすユーザー(参照者は含めない = 2.4 の「通知」)。d に有効なユーザーへの絞り込みは呼び手が行う(所属する人だけは、所属が d に成り立つ人)。
     * {@see self::constrain()} と同じ関係者の印の付いた項目と明細の行の結び方で引く(定義を 1 か所にする)。通知の宛先の候補({@see RecordAudience})が使う。
     *
     * @param  RecordModule  $module  親の無い業務モジュール(表から全部の列を読んだもの)
     * @return list<int> ユーザーの id(昇順・重ねない)。この業務モジュールのレコードが無ければ空
     *
     * @throws LogicException 業務モジュールが親の列を読んでいない・明細のモジュールのとき(作りの誤り)
     */
    public function relatedUserIds(RecordModule $module, int $recordId, DateTimeInterface $date): array
    {
        $this->ensureLoadedTopLevel($module);
        $record = Record::query()->where('record_module_id', $module->id)->whereKey($recordId)->first();

        if ($record === null) {
            return [];
        }

        $references = $this->flaggedReferences($module, $record->id);
        $departmentIds = $this->idsIn($references, 'department_id');
        $memberIds = $departmentIds === [] ? [] : $this->members->userIdsOn($departmentIds, null, $date);
        $creatorIds = array_filter([$record->created_by_user_id]);
        $userIds = array_unique([...$creatorIds, ...$this->idsIn($references, 'user_id'), ...$memberIds]);
        sort($userIds);

        return $userIds;
    }

    /**
     * 明細のモジュールの行を親の判定なしに絞らない側に倒さないため、親の列を読み込んでいない業務モジュールと明細のモジュールを作りの誤りとして止める。
     * 列の有無は生の属性で見る(キャストの宣言があると hasAttribute() はいつも真)。
     */
    private function ensureLoadedTopLevel(RecordModule $module): void
    {
        $attributes = $module->getAttributes();

        if (! array_key_exists('parent_record_module_id', $attributes)
            || $attributes['parent_record_module_id'] !== null) {
            throw new LogicException("行の判定に渡す業務モジュール {$module->id} は、表から全部の列を読んだ親の無いものにする");
        }
    }

    /**
     * 関係者の印の付いた項目の id(own = 業務モジュールの項目、details = 明細のモジュールの項目)。
     *
     * @return array{own: list<int>, details: list<int>}
     */
    private function flaggedFieldIds(RecordModule $module): array
    {
        $modules = RecordModule::query()->select('id')
            ->whereKey($module->id)
            ->orWhere('parent_record_module_id', $module->id);
        $fields = RecordField::query()->where('grants_row_access', true)->whereIn('record_module_id', $modules)->get();

        return [
            'own' => array_values($fields->where('record_module_id', $module->id)->modelKeys()),
            'details' => array_values($fields->where('record_module_id', '!=', $module->id)->modelKeys()),
        ];
    }

    /**
     * EXISTS の副問い合わせを作る関数: 行(表の別名)の、関係者の印の付いた項目の参照の値の行で、相手が u か u の所属する組織のもの。
     *
     * @param  list<int>  $departmentIds
     * @return Closure(string, list<int>): (Closure(QueryBuilder): QueryBuilder)
     */
    private function referenceMatches(int $userId, array $departmentIds): Closure
    {
        return fn (string $table, array $fieldIds) => fn (QueryBuilder $references) => $references
            ->from('record_references')
            ->whereColumn('record_references.record_module_id', "{$table}.record_module_id")
            ->whereColumn('record_references.record_id', "{$table}.id")
            ->whereIn('record_references.record_field_id', $fieldIds)
            ->where(fn (QueryBuilder $target) => $target
                ->where('record_references.user_id', $userId)
                ->when($departmentIds !== [], fn (QueryBuilder $departments) => $departments
                    ->orWhereIn('record_references.department_id', $departmentIds)));
    }

    /**
     * EXISTS の副問い合わせ: レコードの参照者の行で、相手が u か u の所属する組織のもの(参照者の式 = 2.4。参照制限の無いレコードは
     * 参照者を持たないので、チェックを見ずに引く)。
     *
     * @param  list<int>  $departmentIds
     * @return Closure(QueryBuilder): QueryBuilder
     */
    private function viewerMatches(int $userId, array $departmentIds): Closure
    {
        return fn (QueryBuilder $viewers) => $viewers
            ->from('record_row_access_viewers')
            ->whereColumn('record_row_access_viewers.record_id', 'records.id')
            ->where(fn (QueryBuilder $target) => $target
                ->where('record_row_access_viewers.user_id', $userId)
                ->when($departmentIds !== [], fn (QueryBuilder $departments) => $departments
                    ->orWhereIn('record_row_access_viewers.department_id', $departmentIds)));
    }

    /**
     * レコード(own の項目)とその明細の行(details の項目)の、関係者の印の付いた項目の参照の値の行。
     *
     * @return Collection<int, RecordReference>
     */
    private function flaggedReferences(RecordModule $module, int $recordId): Collection
    {
        $flagged = $this->flaggedFieldIds($module);
        $rows = Record::query()->select('id')
            ->where('parent_record_module_id', $module->id)
            ->where('parent_record_id', $recordId);

        return RecordReference::query()
            ->where(fn (Builder $own) => $own
                ->where('record_module_id', $module->id)
                ->where('record_id', $recordId)
                ->whereIn('record_field_id', $flagged['own']))
            ->orWhere(fn (Builder $details) => $details
                ->whereIn('record_id', $rows)
                ->whereIn('record_field_id', $flagged['details']))
            ->get(['user_id', 'department_id']);
    }

    /**
     * 参照の値の行の、相手の列(user_id か department_id)の値のうち null でないもの。
     *
     * @param  Collection<int, RecordReference>  $references
     * @return list<int>
     */
    private function idsIn(Collection $references, string $column): array
    {
        return array_values($references->pluck($column)->filter()->map(fn (mixed $id) => intval($id))->all());
    }
}
