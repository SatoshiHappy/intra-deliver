<?php

namespace App\Actions\Workflow;

/**
 * フローの定義の管理の 422 の文言の 1 か所(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 6 節)。
 */
final class FlowDefinitionErrors
{
    /** %1$d = 最後の版の番号、%2$s = その開始日 */
    public const START_DATE_AFTER_LAST = '開始日は版 %1$d の開始日 %2$s より後の日付を入力してください';

    public const START_DATE_WITHIN_YEAR = '開始日は今日から 1 年以内の日付を入力してください';

    public const VERSION_ALREADY_DELETED = 'この版は既に無効です';

    public const VERSION_ALREADY_ENABLED = 'この版は既に有効です';

    public const SUBJECT_MISSING = '対象者を選択してください';

    public const NOT_ALLOWED = 'このステップには、この設定はできません';

    /** 正本に無い文言(設計/画面の意匠/文言.md の 11 節の規則に合わせた) */
    public const TARGET_KIND_REQUIRED = '指定の種類を選択してください';

    public const TARGETS_REQUIRED = '対象者を 1 件以上指定してください';

    public const RELATIVE_BASE_REQUIRED = '基準にする人を選択してください';

    public const RELATIVE_SCOPE_REQUIRED = '組織を選択してください';

    public const COMPANY_POST_MISSING = '役職を選択してください';

    public const DESIGNATOR_INVALID = '処理対象者を指定するステップには、このステップより前の、必ず通り省略されない承認のステップを選択してください';

    public const ASSIGNEES_RANGE = '人数の最小は 0 から 20、最大は 1 から 20 の範囲で、最小は最大以下にしてください';

    /** 正本に無い文言(設計/画面の意匠/文言.md の 11 節の規則に合わせた) */
    public const DECISION_RULE_REQUIRED = '可決の条件を選択してください';

    public const COUNT_MIN_ZERO = '可決の条件を人数にする場合は、人数の最小を 1 以上にしてください';

    public const DECISION_COUNT_RANGE = '必要な人数は 1 から人数の最小までの範囲で入力してください';

    public const DECISION_RATIO_RANGE = '必要な割合は 1 から 100 の範囲で入力してください';

    public const SEND_BACK_INVALID = '差戻しできる先には、このステップに至る経路上の、申請または承認のステップ(横配置・縦配置を含む)を選択してください';

    public const DESIGNATING_MIN_ZERO = 'このステップはほかのステップの処理対象者を指定するため、人数の最小は 1 以上にしてください';

    public const PREVIOUS_BASE_BLOCKED = 'このステップの直前は合流か合議のため、前の承認者を基準にできません';

    public const SKIPPABLE_MIN_ZERO = 'このステップの後に前の承認者を基準にするステップがあるため、人数の最小は 1 以上にしてください';
}
