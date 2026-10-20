<?php

namespace App\Enums\Records;

/**
 * 更新履歴の版の操作(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節。表 record_revisions の action)。
 */
enum RecordRevisionAction: string
{
    /** 登録の版(版 1) */
    case Create = 'create';

    /** 更新の版 */
    case Update = 'update';
}
