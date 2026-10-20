<?php

namespace App\Actions\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use DateTimeInterface;

/**
 * 対象者を作る業務処理に渡す条件 1 つ(authz_subject_conditions の 1 行の値。docs の 設計/共通マスタ/ロールと認可/対象者.md)。
 * 相手(サブジェクトか子の対象者)を 1 つだけ指すことと、比較を組織・役職でだけ使うことは DB の検査制約が止める。
 * 例: ロール = new SubjectConditionInput(roleId: 3)、認証状態 = new SubjectConditionInput(metaSubject: MetaSubject::Anonymous)。
 */
final readonly class SubjectConditionInput
{
    /**
     * @param  DateTimeInterface|null  $termStartDate  期間の開始日(含む)
     * @param  DateTimeInterface|null  $termEndDate  期間の終了日(含まない)
     * @param  int|null  $childSubjectGroupId  子の対象者(式の入れ子)
     * @param  bool  $isNegated  除外
     */
    public function __construct(
        public ?int $userId = null,
        public ?int $departmentId = null,
        public ?int $companyPostId = null,
        public ?int $roleId = null,
        public ?string $ipv4Pattern = null,
        public ?MetaSubject $metaSubject = null,
        public ?DateTimeInterface $termStartDate = null,
        public ?DateTimeInterface $termEndDate = null,
        public ?int $childSubjectGroupId = null,
        public ConditionOperator $operator = ConditionOperator::Equal,
        public bool $isNegated = false,
    ) {}

    /**
     * authz_subject_conditions の列の値(対象者の列を除く)。
     *
     * @return array<string, int|string|bool|null>
     */
    public function attributes(): array
    {
        return [
            'user_id' => $this->userId,
            'department_id' => $this->departmentId,
            'company_post_id' => $this->companyPostId,
            'role_id' => $this->roleId,
            'ipv4_pattern' => $this->ipv4Pattern,
            'meta_subject' => $this->metaSubject?->value,
            'term_start_date' => $this->termStartDate?->format('Y-m-d'),
            'term_end_date' => $this->termEndDate?->format('Y-m-d'),
            'child_authz_subject_group_id' => $this->childSubjectGroupId,
            'operator' => $this->operator->value,
            'is_negated' => $this->isNegated,
        ];
    }
}
