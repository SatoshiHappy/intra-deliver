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
     * @param  list<string>  $viewerNames  閲覧者の表示名(並び = 項目の並び)
     * @param  list<string>  $departmentNames  閲覧組織の名前
     */
    public function __construct(
        public ?string $title,
        public ?string $heldAt,
        public array $viewerNames,
        public array $departmentNames,
        public ?string $agenda,
        public ?string $notes,
    ) {}
}
