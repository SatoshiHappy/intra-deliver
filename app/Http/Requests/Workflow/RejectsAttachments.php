<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use Closure;
use Illuminate\Validation\Validator;

/**
 * 添付を受けない口が、送られた attachments を黙って捨てず 422 にする規則(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節)。
 * PHP の上限で受け取りに失敗したファイルは prohibited が空と数えるので、ファイルが届いたかでも見る。
 */
trait RejectsAttachments
{
    /**
     * @return array<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->files->has('attachments') && ! $validator->errors()->has('attachments')) {
                $validator->errors()->add('attachments', CaseAttachmentRules::NOT_ATTACHABLE);
            }
        }];
    }

    /**
     * @return array<string, list<string>>
     */
    private function attachmentRules(): array
    {
        return ['attachments' => ['prohibited']];
    }

    /**
     * @return array<string, string>
     */
    private function attachmentMessages(): array
    {
        return ['attachments.prohibited' => CaseAttachmentRules::NOT_ATTACHABLE];
    }
}
