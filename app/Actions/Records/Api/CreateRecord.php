<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\LocksRecordModule;
use App\Actions\Records\RecordAttachmentGate;
use App\Actions\Records\RecordAttachmentWriter;
use App\Actions\Records\RecordRevisionWriter;
use App\Actions\Records\RecordSaver;
use App\Actions\Records\RecordWriteTransaction;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordModuleGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * レコードを登録する(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9 節。明細の行も作る)。業務モジュールの勧告ロックを共有で取る({@see LocksRecordModule})。
 * 判定は業務モジュールの create(認可とメニュー/README.md 2.2)。作成者・更新者は判定の入力のユーザー(ゲストなら null)。
 * 入力の添付は値の後に付け({@see RecordAttachmentWriter}。台帳の鍵は業務モジュールの鍵の後 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節)、最後に更新履歴の版 1(create)を書く
 * ({@see RecordRevisionWriter}。契機と代理の列の向き = 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 2 節)。
 *
 * 業務データの入れ物の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表)。自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる。
 */
class CreateRecord
{
    use LocksRecordModule;
    use RecordWriteTransaction;

    public function __construct(
        private RecordModuleGate $gate,
        private RecordDefinitionQuery $definitions,
        private RecordSaver $saver,
        private RecordAttachmentGate $attachmentGate,
        private RecordAttachmentWriter $attachments,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * @param  RecordRevisionOrigin|null  $origin  版の契機(契機を持つ呼び手だけが渡す。業務の画面の入口は null)
     * @return int 作ったレコードの id
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws AuthorizationException 登録を許されないとき
     * @throws ValidationException 検証に失敗したとき・添付を使用しない業務モジュールに添付を渡したとき(キー attachments)
     * @throws InvalidArgumentException 契機の実行者が AccessContext の利用者と同じとき・上げ済みの実体が持ち主の無い実体でないとき
     */
    public function execute(
        AccessContext $context,
        string $moduleCode,
        RecordInput $input,
        ?RecordRevisionOrigin $origin = null,
    ): int {
        $requester = Requester::fromAccessContext($context);
        $this->revisions->ensureExecutorIsNotUser($origin, $context->userId);

        return $this->writeRecords(function () use ($context, $moduleCode, $input, $origin, $requester) {
            $module = $this->gate->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $this->gate->authorize($requester, $module, RecordModuleAction::Create, $context->date);
            $this->attachmentGate->ensureEnabledFor($module, $input);
            $definition = $this->definitions->load($module);
            $record = $this->saver->save($requester, $context->date, $context->userId, $definition, null, $input);
            $this->attachments->attachInput($record, $origin->executorUserId ?? $context->userId, $input);
            $this->revisions->write($record->id, RecordRevisionAction::Create, $context->userId, $origin);

            return $record->id;
        });
    }
}
