<?php

namespace App\Queries\Workflow;

use App\Queries\Upload\Api\UploadLimits;
use LogicException;

/**
 * 申請の画面と処理のダイアログが受ける添付の上限と案内(AttachmentLimits = docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節。
 * 送る前の確かめ = 同じ文書の 4 節)。数と文だけで、レコードの値を持たない。値と案内は {@see UploadLimits} の公開の口から作る。
 *
 * @phpstan-type AttachmentLimits array{maxFileBytes: int, maxRequestBytes: int, maxFiles: int, accept: string,
 *     guide: string, tooLarge: string}
 */
class WorkflowAttachmentLimits
{
    private const UNITS = ['GB' => 1_073_741_824, 'MB' => 1_048_576, 'KB' => 1_024];

    public function __construct(private UploadLimits $limits) {}

    /**
     * @return AttachmentLimits
     *
     * @throws LogicException 設定の値が正の整数でない・対応表の拡張子に案内の名前が無いとき
     */
    public function props(): array
    {
        $maxFileBytes = $this->limits->maxFileBytes();
        $maxRequestBytes = $this->limits->maxRequestBytes();
        $extensions = array_keys($this->limits->formats());

        return [
            'maxFileBytes' => $maxFileBytes,
            'maxRequestBytes' => $maxRequestBytes,
            'maxFiles' => UploadLimits::MAX_FILES_PER_REQUEST,
            'accept' => implode(',', array_map(fn (string $extension) => ".{$extension}", $extensions)),
            'guide' => $this->limits->guide(),
            'tooLarge' => '送信する内容が大きすぎます。添付ファイルは 1 回 '.UploadLimits::MAX_FILES_PER_REQUEST.' ファイル・合計 '
                .self::size($maxRequestBytes).'、1 ファイル '.self::size($maxFileBytes).' までです。',
        ];
    }

    /**
     * 詳細・一覧のページの attachmentLimits(そのページのどの入口の canAttach も偽なら null = 5 節)。
     *
     * @return AttachmentLimits|null
     *
     * @throws LogicException 設定の値が正の整数でない・対応表の拡張子に案内の名前が無いとき
     */
    public function forPage(bool $anyAttachable): ?array
    {
        return $anyAttachable ? $this->props() : null;
    }

    /**
     * バイト数を割り切れる最大の単位で書く(案内の 1 行 {@see UploadLimits::guide()} の数と同じ書き方)。
     */
    private static function size(int $bytes): string
    {
        foreach (self::UNITS as $unit => $base) {
            if ($bytes % $base === 0) {
                return intdiv($bytes, $base).$unit;
            }
        }

        return $bytes.'B';
    }
}
