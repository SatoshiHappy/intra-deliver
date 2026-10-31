<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\LockedRecordUpdate;
use App\Actions\Records\LocksRecordModule;
use App\Actions\Records\RecordRevisionWriter;
use App\Actions\Records\RecordWriteTransaction;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * レコードを判定せずに更新する公開の口(AI の書き込みの口だけが呼ぶ = ADR 0029 の決定 6・7。規則 = docs の
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2 の「判定しない更新」、保証の正本 =
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表)。業務モジュールの判定(update)と行の判定だけを行わず、
 * ロックの順・持ち主の規則(鍵を受けない)・3〜9・添付・更新履歴の版は判定する更新 {@see UpdateRecord} と同じ
 * ({@see LockedRecordUpdate} を通る)。版を受けない(比べない)。
 */
class UpdateRecordUnchecked
{
    use LocksRecordModule;
    use RecordWriteTransaction;

    public function __construct(
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private LockedRecordUpdate $update,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * 主体の判定をしない。呼び手(AI の書き込みの口)の入口が、依頼の時点に依頼者の更新を判定済み(設計/AI/公開の口.md 3 節の
     * 「依頼の時点の確かめ」)。
     *
     * $context は更新者・版の操作した人(その日に有効かを確かめない)と基準日(検証とユーザー・組織の名前の時点)の出どころで、判定に使わない
     * (保存に判定する者を渡さない)。受けない入力は 9.2 の 3 の段で作りの誤りとして断る(主体なしで確かめられないため = ADR 0029 の決定 6)。
     * 外側のトランザクションの中では savepoint になり、デッドロックを含む例外はやり直さずに伝わる(やり直すかは呼び手が決める)。
     *
     * @param  RecordRevisionOrigin  $origin  版の契機(いつも渡す)
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、レコードがその業務モジュールに無い
     *                                                     (明細の行を含む)とき
     * @throws ValidationException 持ち主があるとき(キー record)、検証に失敗したとき・添付を使用しない業務モジュールに
     *                             添付を渡したとき(キー attachments)
     * @throws LogicException 受けない入力を渡したとき(呼び手の作りの誤り)
     * @throws InvalidArgumentException 契機の実行者が AccessContext の利用者と同じとき・上げ済みの実体が持ち主の無い実体でないとき
     */
    public function execute(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        RecordInput $input,
        RecordRevisionOrigin $origin,
    ): void {
        $this->revisions->ensureExecutorIsNotUser($origin, $context->userId);

        $this->writeRecords(function () use ($context, $moduleCode, $recordId, $input, $origin): void {
            $module = $this->gate->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $record = Record::query()->where('record_module_id', $module->id)
                ->lock('for no key update')->findOrFail($recordId);
            $this->update->throwIfHeld($record, null);
            $loaded = $this->definitions->load($module);
            self::throwIfUnacceptedInput($loaded, $input);

            $this->update->write(null, $context, $loaded, $record, $input, $origin);
        });
    }

    /**
     * 受けない入力を断る(項目に無いキーは断らず、検証の 422 に任せる = 9.2 の 3)。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     *
     * @throws LogicException 受けない入力を渡したとき
     */
    private static function throwIfUnacceptedInput(RecordModule $module, RecordInput $input): void
    {
        self::throwIfRowAccessInput($input);

        if ($input->details !== []) {
            throw new LogicException('判定しない更新は明細の入力を受けない: '.implode(', ', array_keys($input->details)));
        }

        $fields = $module->recordFields->keyBy('code');

        foreach (array_keys($input->values) as $code) {
            $field = $fields->get($code);

            if ($field instanceof RecordField && $field->type === RecordFieldType::Record) {
                throw new LogicException("判定しない更新は他モジュール参照の項目を受けない: {$code}");
            }

            if ($field instanceof RecordField && $field->grants_row_access) {
                throw new LogicException("判定しない更新は関係者の印の付いた項目を受けない: {$code}");
            }
        }
    }

    /**
     * 参照制限の 2 つのキー(どちらかが null でない = 指定した)を断る(参照制限と関係者を決める入力 = 設計/ワークフロー/業務データとの接続/公開の口.md の 7 節の判定しない更新の行)。
     *
     * @throws LogicException どちらかを渡したとき
     */
    private static function throwIfRowAccessInput(RecordInput $input): void
    {
        if ($input->rowAccessRestricted !== null || $input->rowAccessViewers !== null) {
            throw new LogicException('判定しない更新は参照制限の入力(rowAccessRestricted・rowAccessViewers)を受けない');
        }
    }
}
