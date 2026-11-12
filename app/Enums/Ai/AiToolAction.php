<?php

namespace App\Enums\Ai;

/**
 * 道具の約束の「要る権限」(docs の 設計/AI/道具と権限/README.md 10.1 の約束の表と性質の表)。対象の業務モジュールの操作の閲覧・登録・更新。
 * 要る権限が無い道具は null で表す(性質「ファイルを作る」)。
 */
enum AiToolAction: string
{
    /** 閲覧 */
    case View = 'view';

    /** 登録 */
    case Create = 'create';

    /** 更新 */
    case Update = 'update';
}
