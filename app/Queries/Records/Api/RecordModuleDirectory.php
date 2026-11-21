<?php

namespace App\Queries\Records\Api;

use App\Models\Records\RecordModule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務モジュールの定義の読み取りの口(業務モジュールの一覧・表示の止め・業務モジュールの定義。主体・認可・検証の範囲・ロック・原子性・結果・失敗の
 * 正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の RecordModuleDirectory の 3 行)。
 * どれもレコードを返さない。鍵を取らない。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 */
class RecordModuleDirectory
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordModuleVisibilityStopCatalog $visibilityStops,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * 主体の判定をしない。定義の読み取りで、レコードを返さない。
     *
     * 親の無い業務モジュール全部のコードと名前(表示の止めのある業務モジュールも含む)。並び = sort_key・code(管理の画面の参照先の候補と同じ)。
     *
     * @return list<array{code: string, name: string}>
     */
    public function topLevelModules(): array
    {
        return array_values(RecordModule::query()->whereNull('parent_record_module_id')
            ->orderBy('sort_key')->orderBy('code')->get(['code', 'name'])
            ->map(fn (RecordModule $module) => ['code' => $module->code, 'name' => $module->name])->all());
    }

    /**
     * 主体の判定をしない。
     *
     * 業務モジュールの表示の止めの理由か null(止めの実装を登録の順に束ねる内部の 1 か所 {@see RecordModuleVisibilityStopCatalog} を通す)。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function visibilityStopReason(string $moduleCode): ?string
    {
        return $this->visibilityStops->stopReason($this->gate->topLevelModule($moduleCode)->id);
    }

    /**
     * 主体の判定をしない。定義の読み取りで、レコードを返さない。
     *
     * 画面と道具が使う業務モジュールの定義(判定を通す {@see RecordFormQuery::definition()} と同じ形。相対の既定値は読んだ時の値)。
     *
     * @return ModuleDefinition
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function definitionUnchecked(string $moduleCode): array
    {
        return $this->definitions->definition($this->definitions->load($this->gate->topLevelModule($moduleCode)));
    }
}
