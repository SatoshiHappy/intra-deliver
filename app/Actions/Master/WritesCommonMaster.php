<?php

namespace App\Actions\Master;

use App\Actions\Master\Api\CommonMasterWriteLock;

/**
 * 共通マスタの書き込みの業務処理の入口。ほかのモジュールは公開の口 {@see CommonMasterWriteLock} を直接使う
 * (内部への依存の数を増やさないため。既存の使い手はこの名前のまま)。
 */
trait WritesCommonMaster
{
    use CommonMasterWriteLock;
}
