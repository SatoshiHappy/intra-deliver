<?php

namespace App\Queries\Meeting;

/**
 * 議事録の材料にする会議の欄(依頼者の判定で読んだ値。docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3)。値の無い欄は null か空。
 */
final readonly class MeetingFields
{
    /**
     * @param  string|null  $title  今の件名
     * @param  string|null  $heldAt  日時(保存形 = UTC の ISO 8601)
     * @param  list<string>  $userNames  関係者のユーザーの表示名(並び = 項目の値の並び)
     * @param  list<string>  $departmentNames  関係者の組織の名前(並び = 項目の値の並び)
     */
    public function __construct(
        public ?string $title,
        public ?string $heldAt,
        public array $userNames,
        public array $departmentNames,
        public ?string $agenda,
        public ?string $notes,
    ) {}
}
