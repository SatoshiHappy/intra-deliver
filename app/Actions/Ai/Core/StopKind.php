<?php

namespace App\Actions\Ai\Core;

/**
 * 確かめの口が返す止める区分(docs の 設計/AI/会話の中核.md 13.5。位置ごとに返せる区分 = {@see CheckPoint::allows()})。
 * 中身(止める印・時間・費用)は受け手が当てる。
 */
enum StopKind: string
{
    /** 利用者(止める印) */
    case User = 'user';

    /** 時間の上限 */
    case TimeLimit = 'time_limit';

    /** 費用の上限 */
    case CostLimit = 'cost_limit';
}
