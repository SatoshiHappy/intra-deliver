<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 他モジュール参照の項目の候補(docs の 設計/業務データの入れ物/画面/README.md 4 節、ADR 0008)。判定は業務モジュールの read(認可とメニュー/README.md 2.2)
 * と、行の判定の条件({@see RecordRowAccess}。候補に出ないレコードは参照に書けない = 2.4)。
 */
class RecordCandidatesQuery
{
    /** 候補の上限 */
    public const LIMIT = 10;

    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
    ) {}

    /**
     * $excludedId は候補から除くレコードの id(SQL で除くので 10 件は減らない。null = 除かない)。
     *
     * @return list<array{id: int, label: string|null}>
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException 業務モジュールの参照を許されないとき
     */
    public function candidates(Requester $requester, string $moduleCode, string $keyword, ?int $excludedId, DateTimeInterface $date): array
    {
        $module = $this->gate->topLevelModule($moduleCode);
        $this->gate->authorize($requester, $module, RecordModuleAction::Read, $date);
        $title = RecordField::query()->where('record_module_id', $module->id)->where('is_title', true)->first();
        $id = preg_match('/^[1-9][0-9]{0,17}$/D', $keyword) === 1 ? (int) $keyword : null;
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';

        $records = Record::query()->where('record_module_id', $module->id);

        return array_values($this->rows->constrain($records, $requester, $module, $date)
            ->when($excludedId !== null, fn (Builder $query) => $query->where('records.id', '<>', $excludedId))
            ->when($keyword !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->when($title !== null, fn (Builder $titled) => $titled->orWhereRaw('(records.field_values ->> ?) ILIKE ?', [(string) $title?->id, $pattern]))
                ->when($id !== null, fn (Builder $identified) => $identified->orWhere('records.id', $id))
                ->when($title === null && $id === null, fn (Builder $nothing) => $nothing->whereRaw('false'))))
            ->orderByDesc('records.id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Record $record) use ($title) {
                $label = $title === null ? null : ($record->field_values[$title->id] ?? null);

                return ['id' => $record->id, 'label' => is_string($label) ? $label : null];
            })
            ->all());
    }
}
