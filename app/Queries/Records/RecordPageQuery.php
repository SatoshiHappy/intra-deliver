<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordWriteDestination;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務の画面の props のうち、業務モジュールの定義 module と、ボタンを出すかの can、レコードの持ち主 hold(docs の 設計/業務データの入れ物/画面/業務の画面.md 2 節、
 * 認可とメニュー/README.md 2.2)。定義は判定(画面が求めるアクション)の後に作る。can は判定の結果を返し、レコードの update・delete は加えて持ち主が無いときだけ真
 * (隠すのは案内)。持ち主の読み取りはトランザクションを求めない(書き込みのときに業務処理が同じ口でもう一度確かめる)。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 */
class RecordPageQuery
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private RecordHoldCatalog $holds,
        private RecordRowAccess $rows,
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
     * アクションごとに許されるか(アクションの ID → 真偽)。レコードの画面で持ち主があるとき($held。{@see self::hold()} の結果が null でない)は、
     * update・delete は偽(判定 かつ 持ち主なし = 認可とメニュー/README.md 2.2)。
     *
     * @param  list<RecordModuleAction>  $actions
     * @return array<string, bool>
     */
    public function can(Requester $requester, RecordModule $opened, array $actions, DateTimeInterface $date, bool $held = false): array
    {
        $can = [];

        foreach ($actions as $action) {
            $blockedByHold = $held && in_array($action, [RecordModuleAction::Update, RecordModuleAction::Delete], true);
            $can[$action->value] = ! $blockedByHold && $this->gate->allows($requester, $opened, $action, $date);
        }

        return $can;
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
