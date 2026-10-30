<?php

namespace App\Enums\Builtin;

/**
 * 組み込みの業務モジュール「会議」の項目のコード(case の値)と定義(docs の 設計/業務ツール/会議/README.md の 1.2、
 * 置き場 = 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」)。case の並び = 項目の並び。
 * 定義は項目を足す業務処理の入力の形(型は入れ物の型の値の文字列。Builtin はどのモジュールにも依存しない)。
 */
enum Meeting: string
{
    /** 業務モジュールのコード */
    public const CODE = 'meeting';

    /** プロジェクトの参照の参照先の業務モジュールのコード(プロジェクトは組み込みにしない) */
    public const PROJECT_MODULE = 'project';

    /** 議事録の欄(AI が書く 5 欄)の最大の文字数(会議/README.md の 1.2) */
    public const MINUTES_MAX_LENGTH = 20000;

    /** 一覧に出し、検索条件にする */
    private const LISTED = ['is_listed' => true, 'is_searchable' => true];

    /** 一覧に出さず、検索条件にする(検索条件は全項目 = 項目を足す画面の初期値と同じ) */
    private const UNLISTED = ['is_listed' => false, 'is_searchable' => true];

    /** 人が書く複数行の文字列(最大の文字数は入れ物の既定) */
    private const NOTES = ['type' => 'string', 'is_multiline' => true, ...self::UNLISTED];

    /** 議事録の欄(AI が書き、人が直せる複数行の文字列) */
    private const MINUTES = [...self::NOTES, 'max_length' => self::MINUTES_MAX_LENGTH];

    /** 必須で一覧に出す */
    private const REQUIRED_LISTED = ['is_required' => true, ...self::LISTED];

    /** 関係者の印を付けた、複数選べる参照(一覧に出す) */
    private const RELATED = ['is_multiple' => true, 'grants_row_access' => true, ...self::LISTED];

    /** 件名 */
    case Title = 'title';

    /** 日時 */
    case HeldAt = 'held_at';

    /** 関係者(ユーザーと組織を 1 つの項目で選ぶ = ADR 0028 の決定 7) */
    case RelatedParties = 'related_parties';

    /** プロジェクト */
    case Project = 'project';

    /** 削除予定日 */
    case PurgeDueOn = 'purge_due_on';

    /** 要約 */
    case Summary = 'summary';

    /** 話題ごとの詳細 */
    case Topics = 'topics';

    /** 決定事項 */
    case Decisions = 'decisions';

    /** 未決事項 */
    case OpenIssues = 'open_issues';

    /** 次のアクション */
    case NextActions = 'next_actions';

    /** アジェンダ */
    case Agenda = 'agenda';

    /** 議事メモ */
    case Notes = 'notes';

    /**
     * 項目の定義(項目を足す業務処理の入力。並び順は入れない = 足すと末尾に付く)。
     *
     * @param  string  $purgeAfter  削除予定日の既定の期間(ISO 8601。配備の設定 = 設計/業務ツール/会議/通知と削除予定日.md の 7.1)
     * @return array<string, mixed>
     */
    public function definition(string $purgeAfter): array
    {
        return ['code' => $this->value, ...$this->attributes($purgeAfter)];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $purgeAfter): array
    {
        return match ($this) {
            self::Title => ['name' => '件名', 'type' => 'string', 'is_title' => true, ...self::REQUIRED_LISTED],
            self::HeldAt => [
                'name' => '日時', 'type' => 'datetime',
                'default_value' => ['relative' => 'P0D'], ...self::REQUIRED_LISTED,
            ],
            self::RelatedParties => ['name' => '関係者', 'type' => 'user_or_department', ...self::RELATED],
            self::Project => [
                'name' => 'プロジェクト', 'type' => 'record',
                'referenced_module' => self::PROJECT_MODULE, ...self::LISTED,
            ],
            self::PurgeDueOn => [
                'name' => '削除予定日', 'type' => 'date',
                'default_value' => ['relative' => $purgeAfter], ...self::REQUIRED_LISTED,
            ],
            self::Summary => ['name' => '要約', ...self::MINUTES],
            self::Topics => ['name' => '話題ごとの詳細', ...self::MINUTES],
            self::Decisions => ['name' => '決定事項', ...self::MINUTES],
            self::OpenIssues => ['name' => '未決事項', ...self::MINUTES],
            self::NextActions => ['name' => '次のアクション', ...self::MINUTES],
            self::Agenda => ['name' => 'アジェンダ', ...self::NOTES],
            self::Notes => ['name' => '議事メモ', ...self::NOTES],
        };
    }
}
