<?php

namespace App\Http\Requests\Records;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Upload\Api\UploadLimits;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * 添付の追加の入力(multipart の attachments = 1〜3 個。docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 8 節)。
 * ここは形だけを見て、大きさ・名前・形式・合計は業務処理(StoreUploads)が確かめる。PHP の上限で落ちた送信(uploaded の失敗)は、
 * 業務処理と同じキーと文言にそろえる(添付ファイル/README.md の 3 節)。
 */
class RecordAttachmentRequest extends FormRequest
{
    /**
     * 口の順(業務モジュール 404 → update 403 → レコード 404)を検証より前に確かめる(認可とメニュー/README.md 2.2)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function authorize(RecordModuleGate $gate): bool
    {
        $context = AccessContext::of($this);
        $gate->authorizeTarget(
            Requester::fromAccessContext($context),
            (string) $this->route('module'),
            RecordModuleAction::Update,
            $context->date,
            (int) $this->route('record'),
        );

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'attachments' => ['required', 'array', 'list', 'max:'.UploadLimits::MAX_FILES_PER_REQUEST],
            'attachments.*' => ['file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limits = app(UploadLimits::class);
        $messages = [
            'attachments.required' => UploadLimits::NO_FILES,
            'attachments.array' => UploadLimits::NO_FILES,
            'attachments.list' => UploadLimits::NO_FILES,
            'attachments.max' => UploadLimits::TOO_MANY_FILES,
            'attachments.*.file' => UploadLimits::NOT_RECEIVED,
        ];

        // 送られた要素はファイルとは限らない(入れ子の配列など)。ファイルでない要素は規則 file の誤りになる
        $files = $this->files->get('attachments');

        foreach (is_array($files) ? $files : [] as $index => $file) {
            if ($file instanceof SymfonyUploadedFile) {
                $messages["attachments.{$index}.uploaded"] = $limits->uploadErrorMessage($file->getError());
            }
        }

        return $messages;
    }

    /**
     * 送られたファイル(形は検証済み)。
     *
     * @return list<UploadedFile>
     */
    public function attachmentFiles(): array
    {
        $files = $this->file('attachments');

        return is_array($files) ? array_values($files) : [];
    }
}
