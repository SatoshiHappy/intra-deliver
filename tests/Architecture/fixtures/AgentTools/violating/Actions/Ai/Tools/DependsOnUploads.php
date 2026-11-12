<?php

namespace App\Actions\Ai\Tools;

use App\Queries\Upload\Api\UploadQuery;
use App\Queries\Upload\Api\UploadResponses;

/**
 * 道具の名前空間のクラスが、Upload の実体のファイルの場所の読み取りと応答の口に依存するのは禁止(ファイルは一覧の interface だけを通す)
 */
final class DependsOnUploads
{
    public function __construct(
        private UploadQuery $uploads,
        private UploadResponses $responses,
    ) {}

    public function pathOf(int $uploadId): ?string
    {
        return $this->uploads->pathOf($uploadId);
    }

    public function isPreviewable(string $mime): bool
    {
        return $this->responses->isPreviewable($mime);
    }
}
