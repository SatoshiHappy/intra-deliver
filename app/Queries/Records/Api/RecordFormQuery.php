<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\DraftValueReader;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 申請の画面のフォームの口(業務モジュールの定義・判定・一時保存の入力と再申請の初期値 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 1 節・
 * 2 節。主体・認可・結果・失敗の正本 = 同 公開の口.md 7 節の表)。判定は業務データの入れ物の画面と同じ口({@see RecordModuleGate})を通る。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 * @phpstan-import-type DraftValues from DraftValueReader
 * @phpstan-import-type DetailRow from RecordQuery
 */
class RecordFormQuery
{
    /** 業務モジュールの操作を許されないときの 403 の文言(業務データの入れ物の判定とワークフローの再申請の判定が同じ文言で投げる) */
    public const NOT_ALLOWED_MESSAGE = 'この業務モジュールの操作は許可されていません';

    public function __construct(
        private RecordPageQuery $pages,
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private DraftValueReader $drafts,
        private RecordQuery $records,
    ) {}

    /**
     * 業務モジュール(親の無いもの)の $action を判定し(申請の画面 = create、再申請の画面 = update)、定義を返す(設計/業務データの入れ物/画面/README.md 1.2 の
     * RecordModuleDefinition の形)。
     *
     * @return ModuleDefinition
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException $action を許されないとき
     */
    public function definition(Requester $requester, string $moduleCode, RecordModuleAction $action, DateTimeInterface $date): array
    {
        return $this->pages->definition($this->pages->open($requester, $moduleCode, $date, $action));
    }

    /**
     * 判定を求める者が d に、業務モジュール(親の無いもの)の $action を許されるか。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function allows(Requester $requester, string $moduleCode, RecordModuleAction $action, DateTimeInterface $date): bool
    {
        return $this->gate->allows($requester, $this->gate->topLevelModule($moduleCode), $action, $date);
    }

    /**
     * 主体の判定をしない。呼び手が閲覧の判定を済ませてから呼ぶ。
     *
     * 一時保存の入力(業務処理の入力と同じ形 = 項目のコード → 値、明細の項目のコード → 行)を、画面の初期値の形に直す。業務モジュールの判定を
     * しない(呼び手が {@see self::definition()} を通してから呼ぶ)。検証しない。規則は {@see DraftValueReader}。判定を求める者は、他モジュール
     * 参照の相手の表示名の出し分けにだけ使う(参照先の read の読み方 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「行の権限」)。
     *
     * @param  array<array-key, mixed>  $values  項目のコード → 値
     * @param  array<array-key, mixed>  $details  明細の項目のコード → 行(行 = values を持つ配列)
     * @return DraftValues values = 今の定義の項目のコード → 値(設計/業務データの入れ物/画面/README.md 1.2 の RecordFieldValue)、
     *                     details = 明細の項目のコード → 行(values だけ)
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function draftValues(Requester $requester, string $moduleCode, array $values, array $details, DateTimeInterface $date, string $localeCode): array
    {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));

        return $this->drafts->read($module, $values, $details, $requester, $date, $localeCode);
    }

    /**
     * 主体の判定をしない。呼び手が閲覧の判定(definition(Update))を済ませてから呼ぶ。
     *
     * 保存されたレコード(親の無いもの)の値を、業務データの入れ物の編集の画面と同じ初期値の形で返す(再申請の画面の初期値 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節)。
     * 業務モジュールの判定をしない(呼び手が {@see self::definition()} を update で通してから呼ぶ)。検証しない。判定を求める者は、他モジュール
     * 参照の相手の表示名の出し分けにだけ使う(参照先の read の読み方 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「行の権限」)。
     *
     * values = 項目のコード → 値、details = 明細の項目のコード → 行(id と values)、version = 編集の画面と同じ版(既存のデータからの申請が
     * 開いたときの版として送り返す = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節)。
     *
     * @return array{values: array<string, mixed>, details: array<string, list<DetailRow>>, version: string}
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュールのとき、レコードがその業務モジュールに無いとき
     */
    public function editValues(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));
        $editable = $this->records->editable($requester, $module, $recordId, $date, $localeCode);

        return ['values' => $editable['values'], 'details' => $editable['details'], 'version' => $editable['version']];
    }
}
