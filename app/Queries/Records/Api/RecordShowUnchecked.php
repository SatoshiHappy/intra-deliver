<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordValueReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコードを判定せずに読む公開の口(AI の実行の対象のレコードを読む口だけが呼ぶ = ADR 0029 の決定 5・7。保証の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表)。業務モジュールの read と行の判定をせず、判定する者を受けないので、
 * 他モジュール参照の表示名を引かない(label が null = 画面の #<id>。
 * 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の「参照の表示名」)。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type DetailRow from RecordQuery
 *
 * @phpstan-type ShownRecord array{module: ModuleDefinition, record: array{id: int, values: array<string, mixed>,
 *     details: array<string, list<DetailRow>>, createdAt: string|null, updatedAt: string|null,
 *     createdBy: ReferenceValue|null, updatedBy: ReferenceValue|null}}
 */
class RecordShowUnchecked
{
    public function __construct(
        private RecordQuery $records,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * 主体の判定をしない。呼び手(AI の読む口)の入口が、依頼の時点に依頼者の閲覧を判定済み(設計/AI/公開の口.md 3 節の
     * 「依頼の時点の確かめ」)。
     *
     * レコード(親の無いもの)の業務モジュールの定義と値。形は {@see RecordDisplayQuery::show()} と同じで、他モジュール参照の表示名だけが
     * いつも null。ユーザー・組織の名前(参照・作成者・更新者)は $date のもの。
     *
     * @return ShownRecord
     *
     * @throws ModelNotFoundException<Record|RecordModule> レコードが無い・明細の行のとき
     */
    public function show(int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $record = $this->records->topLevel($recordId);
        $module = $this->definitions->load(RecordModule::query()->findOrFail($record->record_module_id));

        return [
            'module' => $this->definitions->definition($module),
            'record' => $this->records->show(null, $module, $record->id, $date, $localeCode),
        ];
    }
}
