<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseInputRules;
use App\Queries\Upload\Api\UploadLimits;
use App\Support\ReadsJsonEnvelope;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * 添付を受けるワークフローの口の入力(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・3.3 節): envelope の受け取りは
 * {@see ReadsJsonEnvelope}(multipart の要求は envelope を JSON の要求と同じ入力に戻す)で、ここは attachments と remove_attachment_ids の
 * 形だけを見る(件数・大きさ・名前・形式は業務処理の StoreUploads が見る)。使う口は {@see self::withAttachmentRules()}・
 * {@see self::withAttachmentMessages()} で規則と文言を包む。
 */
trait ReadsAttachmentEnvelope
{
    use ReadsJsonEnvelope;

    /** remove_attachment_ids の形の誤りと数の上限超え */
    private const REMOVALS_INVALID = '削除する添付ファイルの指定が正しくありません';

    /** 1 回に外す指定の数の上限(一時保存の添付の数には上限が無いので、要求の大きさだけを抑える) */
    private const MAX_REMOVALS = 100;

    /** envelope の大きさの上限(値の上限の倍。復号の前に止めて、本文の上限いっぱいの文字列を配列にしない) */
    private const ENVELOPE_MAX_BYTES = CaseInputRules::DRAFT_MAX_BYTES * 2;

    /**
     * この口が remove_attachment_ids を受けるか(受けない口は送られても捨てる = 3.1 節)。
     */
    abstract protected function acceptsAttachmentRemovals(): bool;

    protected function envelopeMaxBytes(): int
    {
        return self::ENVELOPE_MAX_BYTES;
    }

    /**
     * @param  array<string, list<string>>  $rules  その口の封筒の規則
     * @return array<string, list<string>>
     */
    private function withAttachmentRules(array $rules): array
    {
        $removals = [
            'remove_attachment_ids' => ['array', 'list', 'max:'.self::MAX_REMOVALS],
            'remove_attachment_ids.*' => ['integer'],
        ];

        return $this->withEnvelopeRules([
            ...$rules,
            'attachments' => ['array', 'list'],
            'attachments.*' => ['file'],
            ...($this->acceptsAttachmentRemovals() ? $removals : []),
        ]);
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
