<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\LockedRecordUpdate;
use App\Actions\Records\LocksRecordModule;
use App\Actions\Records\RecordAttachmentWriter;
use App\Actions\Records\RecordRevisionWriter;
use App\Actions\Records\RecordWriteTransaction;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * レコードを更新する(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9 節。入力にキーの無い項目は今のまま。明細は 設計/業務データの入れ物/項目と規則/README.md 6 節)。
 * 業務モジュールの勧告ロックを共有で取り({@see LocksRecordModule})、レコードの行を FOR NO KEY UPDATE で引いて同じレコードの更新・削除を直列にする
 * (設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節。持ち主の規則と開いたときの版を確かめる順 = 9.2 の 2)。
 * 版で見るのは、行の鍵が開いた後の保存を止めないため(データモデル/値の形と直列化.md 4 節)。
 * 業務データの入れ物の入口の更新(持ち主の鍵 null)は、レコードを行の判定({@see RecordRowAccess})の条件で引き、通らなければ 404。持ち主の鍵を渡す
 * 更新(ワークフローの再申請)は、read を見ないのと同じく行の判定を見ない(案件の閲覧の規則で守る = 認可とメニュー/README.md 2.2・2.4)。
 * 入力の添付は値の後に付け({@see RecordAttachmentWriter}。台帳の鍵は行の鍵の後 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節)、最後に更新履歴の版(update)を書く
 * ({@see RecordRevisionWriter}。契機が無く差分が空なら書かない = 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節)。
 *
 * 業務データの入れ物の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表)。自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる。
 * 行の鍵より後ろ(持ち主の規則・3〜9・添付・版)は、判定しない更新 {@see UpdateRecordUnchecked} と同じ {@see LockedRecordUpdate} を通る。
 */
class UpdateRecord
{
    use LocksRecordModule;
    use RecordWriteTransaction;

    /** 開いた後に更新されているときの文言(項目と規則/規則と業務処理/失敗と定義の変更.md 10 節) */
    private const STALE = 'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。';

    public function __construct(
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private RecordRowAccess $rows,
        private LockedRecordUpdate $update,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * @param  string|null  $holder  持ち主の鍵。渡してよいのは持ち主自身(鍵を作った業務処理 = ワークフローの再申請)だけで、業務データの入れ物の入口は
     *                               いつも null を渡す(鍵は HTTP から来ない = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節。
     *                               機械では止めない協調の約束)。鍵を渡す呼び出しは行の判定を見ない
     *                               (認可とメニュー/README.md 2.4 の「申請の経路は行の判定を見ない」、
     *                               設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「行の権限」)
     * @param  string|null  $version  開いたときの版({@see Record::version()} の形)。null = 呼び手が自分で同時の書き込みを止める(持ち主の鍵と改訂番号)。
     *                                既定値を付けない(省いて黙って確かめを外さないように)
     * @param  RecordRevisionOrigin|null  $origin  版の契機(契機を持つ呼び手だけが渡す。業務の画面の入口は null)
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、レコードがその業務モジュールに無い・
     *                                                     行の判定を通らないとき(鍵が null のとき)
     * @throws AuthorizationException 更新を許されないとき
     * @throws ValidationException 渡した鍵と違う持ち主があるとき・開いた後に更新されているとき(キー record)、検証に失敗したとき・
     *                             添付を使用しない業務モジュールに添付を渡したとき(キー attachments)
     * @throws InvalidArgumentException 契機の実行者が AccessContext の利用者と同じとき・上げ済みの実体が持ち主の無い実体でないとき
     */
    public function execute(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        RecordInput $input,
        ?string $version,
        ?string $holder = null,
        ?RecordRevisionOrigin $origin = null,
    ): void {
        $requester = Requester::fromAccessContext($context);
        $this->revisions->ensureExecutorIsNotUser($origin, $context->userId);

        $this->writeRecords(function () use (
            $context, $moduleCode, $recordId, $input, $version, $holder, $origin, $requester,
        ): void {
            $module = $this->gate->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $this->gate->authorize($requester, $module, RecordModuleAction::Update, $context->date);
            $records = Record::query()->where('record_module_id', $module->id);

            if ($holder === null) {
                $records = $this->rows->constrain($records, $requester, $module, $context->date);
            }

            $record = $records->lock('for no key update')->findOrFail($recordId);
            $this->update->throwIfHeld($record, $holder);

            if ($version !== null && $version !== $record->version()) {
                throw ValidationException::withMessages(['record' => [self::STALE]]);
            }

            $this->update->write($requester, $context, $this->definitions->load($module), $record, $input, $origin);
        });
    }
}
