<?php

namespace App\Queries\Upload\Api;

use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * 実体の応答(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・4 節)。主体の判定をしない
 * (呼び手が閲覧と持ち主の一致を判定済み)。範囲の要求に応えるため、実体のパスから BinaryFileResponse を作る。
 */
class UploadResponses
{
    /** プレビューで開ける画像・PDF の型(音声・動画の型は形式の対応表の audio/*・video/* = {@see UploadLimits::mediaMimes()}) */
    private const PREVIEW_MIMES = ['image/png', 'image/jpeg', 'application/pdf'];

    public function __construct(private UploadLimits $limits) {}

    /**
     * プレビューで開ける型か(欄の「プレビュー」を出すかと、preview の 404 が同じ規則を使う)。
     */
    public function isPreviewable(string $mime): bool
    {
        return in_array($mime, [...self::PREVIEW_MIMES, ...$this->limits->mediaMimes()], true);
    }

    /**
     * @param  string  $name  持ち主の行の名前(上げた時に名前の規則を通った最後の部分)
     *
     * @throws ModelNotFoundException<Upload> 行が無い・実体を消した・実体が無いとき
     */
    public function download(int $uploadId, string $name): BinaryFileResponse
    {
        $response = $this->response($this->existing($uploadId));
        // 代替の名前の作り方は FilesystemAdapter::response と同じ(ASCII にして % を除く)
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            str_replace('%', '', Str::ascii($name)),
        );

        return $response;
    }

    /**
     * @throws ModelNotFoundException<Upload> 行が無い・実体を消した・実体が無い・プレビューの型でないとき
     */
    public function preview(int $uploadId): BinaryFileResponse
    {
        $upload = $this->existing($uploadId);

        if (! $this->isPreviewable($upload->mime)) {
            throw (new ModelNotFoundException)->setModel(Upload::class, [$uploadId]);
        }

        $response = $this->response($upload);
        $response->headers->set('Content-Disposition', ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }

    /**
     * 応答を作る前に、行・実体の有無を確かめる(404 を応答の途中で出さない)。
     *
     * @throws ModelNotFoundException<Upload>
     */
    private function existing(int $uploadId): Upload
    {
        $upload = Upload::query()->whereNull('purged_at')->findOrFail($uploadId);

        if (! Storage::disk('local')->exists($upload->path)) {
            throw (new ModelNotFoundException)->setModel(Upload::class, [$uploadId]);
        }

        return $upload;
    }

    private function response(Upload $upload): BinaryFileResponse
    {
        return new BinaryFileResponse(
            Storage::disk('local')->path($upload->path),
            200,
            ['Content-Type' => $upload->mime, 'X-Content-Type-Options' => 'nosniff'],
            public: false,
        );
    }
}
