<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\ApplicantApprovalTerm;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CaseActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 申請者承認防止の当たった項(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節の表): 処理者 = 申請者・実行者 = 申請者・
 * 実行者が申請者の代理先のどれかなら拒み、当たった項を返す。申請者 A・A の代理先 B・第三者 C(A は C の代理先)。DB を読まない本体
 * ({@see ApplicantApprovalGuard::termOf()})に、申請者として扱う人(A と、今日の A の代理先 B)を渡して確かめる。
 */
final class ApplicantApprovalGuardTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    private const C = 3;

    /**
     * @return array<string, array{CaseActor, ApplicantApprovalTerm|null}>
     */
    public static function actors(): array
    {
        return [
            'A 本人(A / A)' => [CaseActor::of(self::A), ApplicantApprovalTerm::ProcessorIsApplicant],
            'B が A として(A / B)' => [new CaseActor(self::A, self::B), ApplicantApprovalTerm::ProcessorIsApplicant],
            'B が本人として(B / B)' => [CaseActor::of(self::B), ApplicantApprovalTerm::ExecutorIsApplicantProxy],
            'A が C として(C / A)' => [new CaseActor(self::C, self::A), ApplicantApprovalTerm::ExecutorIsApplicant],
            'C 本人(C / C)' => [CaseActor::of(self::C), null],
        ];
    }

    #[DataProvider('actors')]
    public function test_returns_the_term_that_rejects_the_actor(CaseActor $actor, ?ApplicantApprovalTerm $term): void
    {
        $this->assertSame($term, ApplicantApprovalGuard::termOf($actor, self::A, [self::A, self::B]));
    }

    public function test_the_message_is_the_applicant_one_unless_only_the_proxy_term_matches(): void
    {
        $this->assertSame([
            ApplicantApprovalGuard::MESSAGE,
            ApplicantApprovalGuard::MESSAGE,
            '申請者の代理先に設定されているため、この申請を承認できません。このステップには、申請者承認防止が設定されています。',
        ], array_map(fn (ApplicantApprovalTerm $term) => ApplicantApprovalGuard::approvalMessage($term), ApplicantApprovalTerm::cases()));
    }

    public function test_the_only_applicant_targets_message_mentions_the_proxy_when_a_proxy_was_removed(): void
    {
        $this->assertSame([
            '承認の処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。',
            '承認の処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。',
            '承認の処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。',
        ], [
            ApplicantApprovalGuard::onlyApplicantTargetsMessage('承認', [self::A], self::A),
            ApplicantApprovalGuard::onlyApplicantTargetsMessage('承認', [self::A, self::B], self::A),
            ApplicantApprovalGuard::onlyApplicantTargetsMessage('承認', [self::B], self::A),
        ]);
    }

    public function test_the_actor_of_one_user_is_the_same_user_as_processor_and_executor(): void
    {
        $actor = CaseActor::of(7);

        $this->assertSame([7, 7], [$actor->processorUserId, $actor->executorUserId]);
    }
}
