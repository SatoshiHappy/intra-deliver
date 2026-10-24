<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordValueReader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコードの表示の口(申請内容の表示と案件名の既定 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 1 節。主体・認可・結果・失敗の正本 =
 * 同 公開の口.md 7 節の表)。業務モジュールの read を見ない(呼び手 = ワークフローの閲覧の判定が先)。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type DetailRow from RecordQuery
 */
class RecordDisplayQuery
{
    public function __construct(
        private RecordQuery $records,
        private RecordDefinitionQuery $definitions,
        private RecordValueReader $reader,
    ) {}

    /**
     * 主体の判定をしない。呼び手が閲覧の判定を済ませてから呼ぶ。
     *
     * レコード(親の無いもの)の業務モジュールの定義と値(業務データの入れ物の詳細の画面と同じ形 = 設計/業務データの入れ物/画面/README.md 1.2・業務の画面.md 2.3)。業務モジュールの read を
     * 見ない。他モジュール参照の表示名だけ、判定を求める者で出し分ける(参照先の read の読み方 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「行の権限」、
     * 項目と規則/README.md 5 節の読むときの規則)。
     *
     * @return array{module: ModuleDefinition, record: array{id: int, values: array<string, mixed>, details: array<string, list<DetailRow>>, createdAt: string|null, updatedAt: string|null, createdBy: ReferenceValue|null, updatedBy: ReferenceValue|null}}
     *
     * @throws ModelNotFoundException<Record|RecordModule> レコードが無い・明細の行のとき
     */
    public function show(Requester $requester, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $record = $this->records->topLevel($recordId);
        $module = $this->definitions->load(RecordModule::query()->findOrFail($record->record_module_id));

        return [
            'module' => $this->definitions->definition($module),
            'record' => $this->records->show($requester, $module, $record->id, $date, $localeCode),
        ];
    }

    /**
     * 主体の判定をしない。呼び手が閲覧の判定を済ませてから呼ぶ。
     *
     * レコード(親の無いもの)の表示の項目の値(案件名の既定に使う)。表示の項目が無い・値が無ければ null。
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function titleOf(int $recordId): ?string
    {
        $record = $this->records->topLevel($recordId);

        return $this->reader->title($record, $this->definitions->titleFieldOf(RecordField::query()->where('record_module_id', $record->record_module_id)->get()));
    }

    /**
     * 主体の判定をしない。呼び手が閲覧の判定を済ませてから呼ぶ。
     *
     * レコードの認可の単位の業務モジュール(親の無い業務モジュール。明細の行なら親の業務モジュール)のコード。レコードが無ければ null。
     */
    public function topLevelModuleCodeOf(int $recordId): ?string
    {
        $moduleId = Record::query()->whereKey($recordId)->selectRaw('coalesce(parent_record_module_id, record_module_id) as module_id')->value('module_id');
        $code = $moduleId === null ? null : RecordModule::query()->whereKey($moduleId)->value('code');

        return is_string($code) ? $code : null;
    }

    /**
     * 主体の判定をしない。閲覧の判定({@see RecordViewAccess})に渡す、認可の単位のレコード(明細の行なら親のレコード)の id。
     * レコードが無ければ null。
     */
    public function topLevelRecordIdOf(int $recordId): ?int
    {
        $id = Record::query()->whereKey($recordId)
            ->selectRaw('coalesce(parent_record_id, id) as top_level_id')
            ->value('top_level_id');

        return $id === null ? null : (int) $id;
    }
}
