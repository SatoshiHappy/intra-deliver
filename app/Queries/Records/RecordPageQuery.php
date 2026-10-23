<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordWriteDestination;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntry;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務の画面の props のうち、業務モジュールの定義 module と、ボタンを出すかの can、レコードの持ち主 hold(docs の 設計/業務データの入れ物/画面/業務の画面.md 2 節、
 * 認可とメニュー/README.md 2.2)。定義は判定(画面が求めるアクション)の後に作る。can は判定の結果だけを返し、持ち主は hold で別に返す
 * (隠すのは案内)。持ち主の読み取りはトランザクションを求めない(書き込みのときに業務処理が同じ口でもう一度確かめる)。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 *
 * @phpstan-type ApplyEntry array{name: string, description: string|null, url: string}
 */
class RecordPageQuery
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private RecordHoldCatalog $holds,
        private RecordRowAccess $rows,
        private RecordApplyEntryCatalog $applyEntryCatalog,
    ) {}

    /**
     * 業務の画面の業務モジュールを開く: 業務モジュールを引き(無い・明細のモジュールなら 404)、画面が求めるアクション(1 つ以上を型で求める)をすべて判定し(403)、
     * 定義を 1 回だけ読み込む。一覧・詳細の読み取りと、定義・can の props は開いたものから作る(引き直さない)。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException 画面のアクションのどれかを許されないとき
     */
    public function open(Requester $requester, string $moduleCode, DateTimeInterface $date, RecordModuleAction $action, RecordModuleAction ...$more): RecordModule
    {
        $module = $this->gate->topLevelModule($moduleCode);

        foreach ([$action, ...$more] as $action) {
            $this->gate->authorize($requester, $module, $action, $date);
        }

        return $this->definitions->load($module);
    }

    /**
     * 詳細・編集の画面のレコード({@see self::open()} で開いた業務モジュールの、親の無いもの)が、その業務モジュールにあって行の判定を通るかを確かめる
     * (認可とメニュー/README.md 2.2 の順の (3)。業務モジュールの判定の後に見る)。
     *
     * @throws ModelNotFoundException<Record> 無い・行の判定を通らないとき(存在を見せない 404)
     */
    public function ensureRowAccess(
        Requester $requester,
        RecordModule $opened,
        int $recordId,
        DateTimeInterface $date,
    ): void {
        $records = Record::query()->where('record_module_id', $opened->id);
        $this->rows->constrain($records, $requester, $opened, $date)->findOrFail($recordId, ['id']);
    }

    /**
     * 業務の書き込み(登録・更新・削除)の後の移り先(認可とメニュー/README.md 2.2「操作の後の移り先」・2.4)。書いた後に開けない画面へ
     * 移さないため、移り先ごとに判定を見直す。業務モジュールは書き込みの業務処理が直前に引いて確かめた後なので、無ければ 404 のまま上げる。
     *
     * @param  int|null  $recordId  書いたレコード(削除は null)
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function destinationAfterWrite(Requester $requester, string $moduleCode, ?int $recordId, DateTimeInterface $date): RecordWriteDestination
    {
        $module = $this->gate->topLevelModule($moduleCode);
        $readable = $this->gate->allows($requester, $module, RecordModuleAction::Read, $date);
        $shown = $readable && $recordId !== null && $this->rows->allows($requester, $module, $recordId, $date);

        return match (true) {
            $shown => RecordWriteDestination::Show,
            $readable => RecordWriteDestination::Index,
            default => RecordWriteDestination::Home,
        };
    }

    /**
     * 画面に渡す定義({@see self::open()} で開いた業務モジュール)。
     *
     * @return ModuleDefinition
     */
    public function definition(RecordModule $opened): array
    {
        return $this->definitions->definition($opened);
    }

    /**
     * アクションごとに許されるか(アクションの ID → 真偽)。持ち主の有無は見ない(画面が hold で出し分ける =
     * レコードの共通機能/詳細の画面/README.md の 5.1 節)。
     *
     * @param  list<RecordModuleAction>  $actions
     * @return array<string, bool>
     */
    public function can(Requester $requester, RecordModule $opened, array $actions, DateTimeInterface $date): array
    {
        $can = [];

        foreach ($actions as $action) {
            $can[$action->value] = $this->gate->allows($requester, $opened, $action, $date);
        }

        return $can;
    }

    /**
     * レコードの詳細の can(詳細の画面/README.md の 6.1 節)。copy = 業務モジュールの create(複製.md の 1 節)、attach = 業務モジュールの update かつ
     * 添付ファイルの追加・削除を許可する、apply = 申請の入口({@see self::applyEntries()} の結果)が空でない。
     *
     * @param  list<ApplyEntry>  $applyEntries
     * @return array{update: bool, delete: bool, copy: bool, attach: bool, apply: bool}
     */
    public function detailCan(
        Requester $requester,
        RecordModule $opened,
        DateTimeInterface $date,
        array $applyEntries,
    ): array {
        $update = $this->gate->allows($requester, $opened, RecordModuleAction::Update, $date);

        return [
            'update' => $update,
            'delete' => $this->gate->allows($requester, $opened, RecordModuleAction::Delete, $date),
            'copy' => $this->gate->allows($requester, $opened, RecordModuleAction::Create, $date),
            'attach' => $update && $opened->is_attachment_enabled,
            'apply' => $applyEntries !== [],
        ];
    }

    /**
     * このレコードから申請できる入口(既存のデータからの申請.md の 2 節の口を束ねたもの。持ち主の有無を見ない = 押せない形は hold で決める)。
     * 業務モジュールの read と行の判定を済ませた後に呼ぶ。
     *
     * @return list<ApplyEntry>
     */
    public function applyEntries(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        return array_map(
            fn (RecordApplyEntry $entry) => [
                'name' => $entry->name,
                'description' => $entry->description,
                'url' => $entry->url,
            ],
            $this->applyEntryCatalog->entriesFor($requester, $recordId, $date),
        );
    }

    /**
     * レコード(親の無いもの)の持ち主の理由(画面/業務の画面.md 2.2・2.3。案件の番号や名前は出さない)。持ち主が無ければ null。
     *
     * @return array{reason: string}|null
     */
    public function hold(int $recordId): ?array
    {
        $hold = $this->holds->blockingHold($recordId, null);

        return $hold === null ? null : ['reason' => $hold->reason];
    }
}
