<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordUploadInput;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * 申請・再申請の入力(docs の 設計/ワークフロー/案件の操作/操作.md 3.5・3.9)。レコードの入力は業務データの入れ物の登録・更新の口へそのまま渡す。
 * 添付はレコードの入力の外に持つ(一時保存から移す実体は業務処理が鍵の下で読んだ行だけにし、呼び手から受けない =
 * 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節)。
 */
final readonly class ApplicationInput
{
    /**
     * @param  string|null  $name  案件名(前後の空白を除いて空なら既定の案件名)
     * @param  string|null  $comment  コメント(前後の空白を除いて空なら null)
     * @param  RecordInput  $record  値と明細(添付を持たない)
     * @param  list<UploadedFile>  $attachFiles  この送信で足す添付(設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節の attachments)
     * @param  list<int>  $removeAttachmentIds  外す一時保存の添付の行の id(一時保存からの申請だけが読む)
     *
     * @throws InvalidArgumentException レコードの入力が添付を持つとき(呼び手の誤り)
     */
    public function __construct(
        public ?string $name,
        public ?string $comment,
        public RecordInput $record,
        public array $attachFiles = [],
        public array $removeAttachmentIds = [],
    ) {
        if ($record->attachFiles !== [] || $record->attachUploads !== []) {
            throw new InvalidArgumentException('添付はレコードの入力でなく attachFiles で渡す');
        }
    }

    /**
     * 業務データの入れ物の口へ渡す入力(値と明細、この送信の添付、一時保存から移す実体、ワークフローが決めた参照制限 = 送らないなら null。
     * 入口が受けた参照制限のキーはここで捨てる = {@see ApplicationRowAccess})。
     *
     * @param  list<RecordUploadInput>  $attachUploads
     */
    public function recordInput(array $attachUploads = [], ?true $rowAccessRestricted = null): RecordInput
    {
        return new RecordInput(
            $this->record->values, $this->record->details, $this->attachFiles, $attachUploads, $rowAccessRestricted,
        );
    }
}
