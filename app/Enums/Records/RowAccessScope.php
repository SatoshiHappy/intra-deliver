<?php

namespace App\Enums\Records;

/**
 * 業務モジュールの見える範囲(record_modules.row_access_scope。docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.4、データモデル/表.md 2.1)。
 * 明細のモジュールはいつも全員(明細の行は親のレコードの判定に従う)。
 */
enum RowAccessScope: string
{
    /** 全員(既定) */
    case All = 'all';

    /** 関係する人だけ */
    case Related = 'related';
}
