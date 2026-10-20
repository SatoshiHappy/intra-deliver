<?php

namespace App\Actions\Records\Api;

use App\Queries\Upload\Api\UploadLimits;
use InvalidArgumentException;

/**
 * 上げ済みの実体を付ける 1 つ(一時保存から申請へ移す添付。docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節)。
 * 名前は形だけを当て直す(行の検査制約と応答の見出しを守るため)。形式の対応表は当てない(上げた後に表が変わっても申請を止めない)。
 */
final readonly class RecordUploadInput
{
    /**
     * @param  int  $uploadId  uploads の id(持ち主の無い実体だけ)
     * @param  string  $name  添付の名前(一時保存の行が持つ値)
     *
     * @throws InvalidArgumentException 名前の形が違うとき(呼び手の誤り)
     */
    public function __construct(
        public int $uploadId,
        public string $name,
    ) {
        if (UploadLimits::nameShapeError($name) !== null) {
            throw new InvalidArgumentException("上げ済みの実体 {$uploadId} の名前の形が違う");
        }
    }
}
