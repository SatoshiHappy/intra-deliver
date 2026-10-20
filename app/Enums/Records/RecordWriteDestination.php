<?php

namespace App\Enums\Records;

/**
 * 業務の書き込み(登録・更新・削除)の後の移り先(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2「操作の後の移り先」)。
 * 決めるのは読み取り(RecordPageQuery::destinationAfterWrite())、ルートへ写すのは入口。
 */
enum RecordWriteDestination: string
{
    /** 詳細(records.data.show) */
    case Show = 'show';

    /** 一覧(records.data.index) */
    case Index = 'index';

    /** ホーム(menu.home) */
    case Home = 'home';
}
