<?php

namespace App\Queries\Alpha\Api\Reading;

use App\Models\Records\Record;
use Illuminate\Database\Eloquent\Collection;

/**
 * 公開の口(配下のサブ名前空間)の読み取りの interface の戻り値に、モデルを型引数に持つコレクション
 */
interface ReturnsModels
{
    /**
     * @return Collection<int, Record>
     */
    public function records(): Collection;
}
