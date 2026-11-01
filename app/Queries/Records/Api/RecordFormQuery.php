<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\DraftValueReader;
use App\Queries\Records\RecordCopyValues;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordParty;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\ReferenceCheck;
use App\Queries\Records\ReferenceTargets;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請の画面のフォームの口(業務モジュールの定義・判定・一時保存の入力と再申請の初期値 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 1 節・
 * 2 節。主体・認可・結果・失敗の正本 = 同 公開の口.md 7 節の表)。判定は業務データの入れ物の画面と同じ口({@see RecordModuleGate})を通る。
 *
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 * @phpstan-import-type DraftValues from DraftValueReader
 * @phpstan-import-type DetailRow from RecordQuery
 * @phpstan-import-type CopiedValues from RecordCopyValues
 * @phpstan-import-type Party from RecordParty
 *
 * @phpstan-type CopiedRows array<string, list<array{values: array<string, mixed>}>>
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
        private RecordCopyValues $copies,
        private ReferenceTargets $targets,
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
     * 主体の判定をしない。呼び手が、そのレコードの値を見てよいことを確かめてから呼ぶ(呼び手ごとの確かめ = docs の
     * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の editValues の行)。
     *
     * 結果の形は `@return` の型(rowAccessRestricted = そのレコードのチェック「参照を制限する」= 7 節の行の結果)。検証の範囲 = 同じ 7 節の editValues の行
     * (version を送り返す使い方 = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節)。判定を求める者は、参照先の表示名の
     * 出し分けにだけ使う(同じ 7 節の「行の権限」)。
     *
     * @return array{values: array<string, mixed>, details: array<string, list<DetailRow>>, version: string,
     *     rowAccessRestricted: bool}
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュールのとき、レコードがその業務モジュールに無いとき
     */
    public function editValues(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));
        $editable = $this->records->editable($requester, $module, $recordId, $date, $localeCode);

        return [
            'values' => $editable['values'],
            'details' => $editable['details'],
            'version' => $editable['version'],
            'rowAccessRestricted' => $editable['rowAccess']['restricted'],
        ];
    }

    /**
     * 主体の判定をしない(業務モジュールの判定と、写す元のレコードの行の判定をしない)。呼び手が案件の閲覧の判定で守ってから呼ぶ。
     *
     * 写しの初期値の「版から」(保証するもの = docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の
     * 13.3 の公開の口の表)。
     *
     * @return array{values: array<string, mixed>, details: CopiedRows, clearedFields: list<string>,
     *     rowAccess: array{restricted: bool, viewers: list<Party>}|null}
     *
     * @throws ModelNotFoundException<RecordModule|RecordRevision> 業務モジュールが無い・明細のモジュールのとき、版が無いとき
     * @throws LogicException 版が写す先の業務モジュールのものでないとき(呼び手が業務モジュールの一致を先に確かめる前提)
     */
    public function copyFromRevision(
        Requester $requester,
        string $moduleCode,
        int $revisionId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));
        $revision = RecordRevision::query()->findOrFail($revisionId);

        return $this->copies->fromRevision($requester, $module, $revision, $date, $localeCode);
    }

    /**
     * 主体の判定をしない(業務モジュールの判定をしない)。写す元の判定は呼び手が済ませてから呼ぶ。
     *
     * 写しの初期値の「値から」(保証するもの = 写す値.md の 13.3 の公開の口の表)。
     *
     * @param  int|null  $targetRecordId  写す先のレコードの id
     * @param  array<array-key, mixed>  $values  項目のコード → 画面の初期値の形の値
     * @param  array<array-key, mixed>  $details  明細の項目のコード → 行(行 = values を持つ配列)
     * @return array{values: array<string, mixed>, details: CopiedRows, clearedFields: list<string>}
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     */
    public function copyFromValues(
        Requester $requester,
        string $moduleCode,
        ?int $targetRecordId,
        array $values,
        array $details,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $module = $this->definitions->load($this->gate->topLevelModule($moduleCode));
        $copied = $this->copies->fromValues(
            $requester, $module, $targetRecordId, $values, $details, $date, $localeCode,
        );

        return [
            'values' => $copied['values'], 'details' => $copied['details'], 'clearedFields' => $copied['clearedFields'],
        ];
    }

    /**
     * 主体の判定をしない(人と組織が日に有効かは業務モジュールに依らない)。
     *
     * 参照者の確かめ(保証するもの = 写す値.md の 13.3 の公開の口の表)。
     *
     * @param  list<Party>  $viewers
     * @return list<Party>
     */
    public function effectiveViewers(array $viewers, DateTimeInterface $date): array
    {
        $check = new ReferenceCheck(null, $date, locksTargets: false);
        $this->targets->prepareParties($check, $viewers);

        return array_values(array_filter(
            $viewers,
            fn (array $viewer) => $this->targets->acceptsParty($viewer, $check),
        ));
    }
}
