<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordHoldCatalog;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 添付の口(追加・外す)の判定と鍵の入口(判定と鍵の順の正本 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節・8 節)。呼び手がトランザクションを持つ。
 * 持ち主の鍵を受けるのは、ワークフローのステップの処理で処理担当者が業務モジュールの update を持たないときのため(同じ文書の 1.1 節)。
 */
class RecordAttachmentGate
{
    use LocksRecordModule;

    /** 使用しない業務モジュールへの追加・取り外しの文言(レコードの添付.md の 2 節) */
    public const DISABLED = 'この業務モジュールでは添付ファイルを追加・削除できません';

    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
        private RecordHoldCatalog $holds,
    ) {}

    /**
     * 業務モジュールとレコードを判定して行の鍵まで取る。持ち主の鍵を渡したときは update と行の判定を見ない
     * (処理の可否は持ち主の側が判定済み。一致は {@see self::ensureWritable()} が確かめる)。
     *
     * @return array{RecordModule, Record}
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュール・レコードが無い・行の判定を通らないとき
     * @throws AuthorizationException 持ち主の鍵が無く、update を許されないとき
     */
    public function lock(AccessContext $context, string $moduleCode, int $recordId, ?string $holder): array
    {
        $requester = Requester::fromAccessContext($context);
        $module = $this->gate->topLevelModule($moduleCode);
        $this->lockRecordModuleShared($module);
        $records = Record::query()->where('record_module_id', $module->id);

        if ($holder === null) {
            $this->gate->authorize($requester, $module, RecordModuleAction::Update, $context->date);
            $this->rows->constrain($records, $requester, $module, $context->date);
        }

        return [$module, $records->lock('for no key update')->findOrFail($recordId)];
    }

    /**
     * 持ち主と使用の判定(行の鍵の後に読む)。
     *
     * @throws ValidationException 渡した鍵と違う持ち主があるとき(キー record)・添付を使用しないとき(キー attachments)
     * @throws InvalidArgumentException 持ち主の鍵を渡したのに、そのレコードの持ち主にその鍵が無いとき(呼び手の誤り)
     */
    public function ensureWritable(RecordModule $module, Record $record, ?string $holder): void
    {
        $hold = $this->holds->blockingHold($record->id, $holder);

        if ($hold !== null) {
            throw ValidationException::withMessages(['record' => [$hold->reason]]);
        }

        // 鍵だけで update と行の判定を飛ばすので、鍵がそのレコードの持ち主のものであることを確かめる
        if ($holder !== null && ! $this->holds->isHeldBy($record->id, $holder)) {
            throw new InvalidArgumentException("レコード {$record->id} の持ち主に鍵 {$holder} が無い");
        }

        $this->ensureEnabled($module);
    }

    /**
     * 業務モジュールが添付を使用するか(使用の判定 = レコードの添付.md の 2 節)。
     *
     * @throws ValidationException 使用しないとき(キー attachments)
     */
    public function ensureEnabled(RecordModule $module): void
    {
        if (! $module->is_attachment_enabled) {
            throw ValidationException::withMessages(['attachments' => [self::DISABLED]]);
        }
    }

    /**
     * 登録・更新の入力に添付があれば、使用の判定をする(認可と同じく値の検証の前)。
     *
     * @throws ValidationException 使用しないとき(キー attachments)
     */
    public function ensureEnabledFor(RecordModule $module, RecordInput $input): void
    {
        if ($input->hasAttachments()) {
            $this->ensureEnabled($module);
        }
    }
}
