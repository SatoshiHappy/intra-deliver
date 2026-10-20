<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\RecordAttachmentGate;
use App\Actions\Records\RecordAttachmentWriter;
use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * レコードに添付を付ける(業務の画面の追加の入口と、ステップの処理で付けるワークフロー。口の保証の正本 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節の表、書く順 = 5 節)。
 * 判定と鍵は {@see RecordAttachmentGate}、付けるのは {@see RecordAttachmentWriter}(台帳の鍵は最後)、最後に版(update)を書く。
 * records の行は書かない(更新日・更新者・Record::version() を変えない)。自分のトランザクションを持ち、外側の中では savepoint で含まれる。
 */
class AttachRecordUploads
{
    public function __construct(
        private RecordAttachmentGate $gate,
        private RecordAttachmentWriter $attachments,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  string|null  $holder  持ち主の鍵。渡すのは持ち主自身だけ(HTTP から来ない)。渡すと業務モジュールの update と行の判定を見ない
     * @param  RecordRevisionOrigin|null  $origin  版の契機(契機を持つ呼び手だけが渡す。業務の画面の入口は null)
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュール・レコードが無いとき
     * @throws AuthorizationException 持ち主の鍵が無く、update を許されないとき
     * @throws ValidationException 持ち主(キー record)・使用しない業務モジュール・ファイルの誤り(キー attachments・attachments.<添字>)
     * @throws InvalidArgumentException 契機の実行者が AccessContext の利用者と同じとき・渡した持ち主の鍵がそのレコードの持ち主に無いとき
     * @throws RuntimeException 実体を書けなかったとき
     */
    public function execute(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        array $files,
        ?string $holder = null,
        ?RecordRevisionOrigin $origin = null,
    ): void {
        $this->revisions->ensureExecutorIsNotUser($origin, $context->userId);

        DB::transaction(function () use ($context, $moduleCode, $recordId, $files, $holder, $origin): void {
            [$module, $record] = $this->gate->lock($context, $moduleCode, $recordId, $holder);
            $this->gate->ensureWritable($module, $record, $holder);
            $this->attachments->attach($record, $origin->executorUserId ?? $context->userId, $files, []);
            $this->revisions->write($record->id, RecordRevisionAction::Update, $context->userId, $origin);
        });
    }
}
