<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseInputRules;
use App\Queries\Upload\Api\UploadLimits;
use App\Support\InputText;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * 添付を受けるワークフローの口の入力(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・3.3 節): multipart の要求は
 * envelope(ファイルの外の入力の JSON)を JSON の要求と同じ入力に戻し、attachments と remove_attachment_ids は形だけを見る
 * (件数・大きさ・名前・形式は業務処理の StoreUploads が見る)。使う口は {@see self::withAttachmentRules()}・{@see self::withAttachmentMessages()} で
 * 規則と文言を包む。
 */
trait ReadsAttachmentEnvelope
{
    /** multipart の envelope が無い・JSON のオブジェクトとして読めない(422 envelope) */
    private const ENVELOPE_UNREADABLE = '送信の形が正しくありません';

    /** remove_attachment_ids の形の誤りと数の上限超え */
    private const REMOVALS_INVALID = '削除する添付ファイルの指定が正しくありません';

    /** 1 回に外す指定の数の上限(一時保存の添付の数には上限が無いので、要求の大きさだけを抑える) */
    private const MAX_REMOVALS = 100;

    /** envelope の大きさの上限(値の上限の倍。復号の前に止めて、本文の上限いっぱいの文字列を配列にしない) */
    private const ENVELOPE_MAX_BYTES = CaseInputRules::DRAFT_MAX_BYTES * 2;

    private bool $envelopeUnreadable = false;

    /**
     * この口が remove_attachment_ids を受けるか(受けない口は送られても捨てる = 3.1 節)。
     */
    abstract protected function acceptsAttachmentRemovals(): bool;

    /**
     * multipart の envelope を入力に置き換える(ファイルはそのまま)。authorize より前に走るので、読めないときは印だけを付け、
     * 422 は authorize の後の検証で出す(404・403 を先に返す順を保つ)。
     */
    protected function prepareForValidation(): void
    {
        if (! str_starts_with(strtolower((string) $this->header('CONTENT_TYPE')), 'multipart/form-data')) {
            return;
        }

        $envelope = $this->post('envelope');
        $decoded = $this->decodeEnvelope($envelope);

        if ($decoded === null) {
            $this->envelopeUnreadable = true;

            return;
        }

        // JSON の要求に掛かる大域の TrimStrings・ConvertEmptyStringsToNull と同じ変換
        $this->replace(InputText::normalize($decoded));
    }

    /**
     * JSON のオブジェクトだけを 1 回で配列にする。リストと大きすぎる文字列は読めないものとする(大きさは値の上限に JSON の分を見込む)。
     *
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(mixed $envelope): ?array
    {
        if (! is_string($envelope) || strlen($envelope) > self::ENVELOPE_MAX_BYTES) {
            return null;
        }

        if (! str_starts_with(ltrim($envelope), '{')) {
            return null;
        }

        $decoded = json_decode($envelope, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, list<string>>  $rules  その口の封筒の規則
     * @return array<string, list<string>>
     */
    private function withAttachmentRules(array $rules): array
    {
        // 読めない envelope は envelope の誤り 1 つだけにする(ほかのキーは送られていないので誤りにしない)
        if ($this->envelopeUnreadable) {
            return [];
        }

        $removals = [
            'remove_attachment_ids' => ['array', 'list', 'max:'.self::MAX_REMOVALS],
            'remove_attachment_ids.*' => ['integer'],
        ];

        return [
            ...$rules,
            'attachments' => ['array', 'list'],
            'attachments.*' => ['file'],
            ...($this->acceptsAttachmentRemovals() ? $removals : []),
        ];
    }

    /**
     * @param  array<string, string>  $messages  その口の封筒の文言
     * @return array<string, string>
     */
    private function withAttachmentMessages(array $messages): array
    {
        $messages = [
            ...$messages,
            'attachments.array' => UploadLimits::NOT_RECEIVED,
            'attachments.list' => UploadLimits::NOT_RECEIVED,
            'attachments.*.file' => UploadLimits::NOT_RECEIVED,
            'remove_attachment_ids.array' => self::REMOVALS_INVALID,
            'remove_attachment_ids.list' => self::REMOVALS_INVALID,
            'remove_attachment_ids.max' => self::REMOVALS_INVALID,
            'remove_attachment_ids.*.integer' => self::REMOVALS_INVALID,
        ];

        // PHP の上限で落ちたファイルは検証の uploaded の失敗になる。文言は業務処理の 1 ファイルの上限と同じにそろえる(3.1 節)
        $files = $this->files->get('attachments');
        $limits = app(UploadLimits::class);

        foreach (is_array($files) ? $files : [] as $index => $file) {
            if ($file instanceof SymfonyUploadedFile) {
                $messages["attachments.{$index}.uploaded"] = $limits->uploadErrorMessage($file->getError());
            }
        }

        return $messages;
    }

    /**
     * 検証の後の印の誤り(FormRequest の after のフック)。
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->envelopeUnreadable) {
                $validator->errors()->add('envelope', self::ENVELOPE_UNREADABLE);
            }
        }];
    }

    /**
     * この送信で足すファイル(形は検証済み)。
     *
     * @return list<UploadedFile>
     */
    public function attachFiles(): array
    {
        $files = $this->file('attachments');

        return is_array($files) ? array_values($files) : [];
    }

    /**
     * 外す一時保存の添付の行の id(受けない口は空。形は検証済み)。
     *
     * @return list<int>
     */
    public function removeAttachmentIds(): array
    {
        if (! $this->acceptsAttachmentRemovals()) {
            return [];
        }

        return array_values(array_map('intval', (array) $this->input('remove_attachment_ids', [])));
    }
}
