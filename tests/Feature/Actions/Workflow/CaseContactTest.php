<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\TransferCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use App\Support\AccessContext;
use Closure;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 連絡(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.5 節、文言 = 同時実行と検査.md の 13 節、検査 = 同じ文書の 14.2 節)。
 * フロー = 申請(u0001)→ 承認 1(u0002)→ 承認 2(u0003)。連絡する人は既定で関わらない u0006(閲覧できない)。
 */
final class CaseContactTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsCaseNotices;
    use RegistersProxies;

    private const DUPLICATE = CaseOperationErrors::CONTACT_DUPLICATE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 規則のそれぞれが 422(キーと 13 節の文言)で、何も書かない(案件・履歴・通知)。
     */
    public function test_each_rule_rejects_without_writing(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $idOf = fn (string $code) => $this->seededUser($code)->id;
        $ids = fn (string ...$codes) => array_values(array_map($idOf, $codes));
        $missing = (int) User::query()->max('id') + 1;
        $others = User::query()->where('code', '!=', 'u0002')->orderBy('id')->limit(11)->pluck('id')->all();
        $eleven = array_values(array_map('intval', $others));
        $cases = [
            [new CaseContact([], 'ひと言だけ'), ['contact.user_ids' => CaseOperationErrors::CONTACT_REQUIRED]],
            [new CaseContact($eleven, null), ['contact.user_ids' => CaseOperationErrors::CONTACT_TOO_MANY]],
            [new CaseContact([$missing], null), ['contact.user_ids' => CaseOperationErrors::CONTACT_NOT_EFFECTIVE]],
            [new CaseContact($ids('u0014'), null), ['contact.user_ids' => CaseOperationErrors::CONTACT_NOT_EFFECTIVE]],
            [new CaseContact($ids('u0002'), null), ['contact.user_ids' => CaseOperationErrors::CONTACT_SELF]],
            [new CaseContact($ids('u0006', 'u0006'), null), ['contact.user_ids' => self::DUPLICATE]],
            [
                new CaseContact($ids('u0006'), str_repeat('あ', 1001)),
                ['contact.message' => CaseOperationErrors::CONTACT_MESSAGE_TOO_LONG],
            ],
        ];

        foreach ($cases as [$contact, $expected]) {
            $before = [$this->workflowCounts(), $this->revisionOf($case)];
            $errors = $this->validationErrors(fn () => $this->approveWith($case, 'u0002', $contact));
            $this->assertSame($expected, $errors);
            $this->assertSame($before, [$this->workflowCounts(), $this->revisionOf($case)]);
        }
    }

    /**
     * ひと言の使えない文字(中の U+0000・UTF-8 として正しくない文字列)は 422 contact.message で、何も書かない(案件・履歴・通知)。
     * 前後の U+0000 は前後の空白として除かれるので、中に置く。
     */
    public function test_a_message_with_unusable_characters_is_rejected_without_writing(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $u0006 = $this->seededUser('u0006')->id;
        $expected = ['contact.message' => CaseOperationErrors::CONTACT_MESSAGE_INVALID_CHARACTER];

        foreach (['U+0000' => "急ぎ\0です", '正しくない UTF-8' => "急ぎ\xC3"] as $name => $message) {
            $before = [$this->workflowCounts(), $this->revisionOf($case)];
            $contact = new CaseContact([$u0006], $message);
            $errors = $this->validationErrors(fn () => $this->approveWith($case, 'u0002', $contact));
            $this->assertSame($expected, $errors, $name);
            $this->assertSame($before, [$this->workflowCounts(), $this->revisionOf($case)], $name);
        }
    }

    /**
     * 成功した操作で選んだ人に連絡ができ、履歴の行は操作の 1 行だけ増え、連絡を受けた人の閲覧の判定は変わらない。ひと言は前後の空白を除き、空なら null。
     * 1000 文字のひと言は通る。
     */
    public function test_a_contact_reaches_the_chosen_people_without_granting_visibility(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $histories = $this->workflowCounts()['histories'];
        $lastId = $this->lastNoticeId();
        $u0006 = $this->seededUser('u0006');

        $this->approveWith($case, 'u0002', new CaseContact([$u0006->id, $this->seededUser('u0010')->id], '  急ぎです  '));

        $contacts = $this->contactsSince($lastId);
        $this->assertSame([
            $this->caseNotice(WorkflowNoticeType::Contact, 'u0006', 'u0002', $case, ['message' => '急ぎです']),
            $this->caseNotice(WorkflowNoticeType::Contact, 'u0010', 'u0002', $case, ['message' => '急ぎです']),
        ], $contacts);
        $this->assertSame($histories + 1, $this->workflowCounts()['histories']);
        $requester = new Requester($u0006->id, null);
        $this->assertFalse(app(CaseVisibility::class)->isVisible($requester, $this->operationDay(), $case->id));

        $other = $this->applyAs($this->seededUser('u0001'));
        $lastId = $this->lastNoticeId();
        $this->approveWith($other, 'u0002', new CaseContact([$u0006->id], "　\n"));
        $expected = [$this->caseNotice(WorkflowNoticeType::Contact, 'u0006', 'u0002', $other, ['message' => null])];
        $this->assertSame($expected, $this->contactsSince($lastId));

        $long = $this->applyAs($this->seededUser('u0001'));
        $lastId = $this->lastNoticeId();
        $this->approveWith($long, 'u0002', new CaseContact([$u0006->id], str_repeat('あ', 1000)));
        $this->assertCount(1, $this->contactsSince($lastId));
    }

    /**
     * 代理先が代理元として操作し、代理元を連絡する人に選ぶと、代理元に連絡が届く(除くのは実行者だけ)。起こした人 = 実行者。
     */
    public function test_a_proxy_can_contact_its_principal(): void
    {
        $this->addProxy($this->seededUser('u0002'), $this->seededUser('u0016'));
        $case = $this->applyAs($this->seededUser('u0001'));
        $lastId = $this->lastNoticeId();

        $this->approveWith($case, 'u0016', new CaseContact([$this->seededUser('u0002')->id], null), 'u0002');

        $expected = [$this->caseNotice(WorkflowNoticeType::Contact, 'u0002', 'u0016', $case, ['message' => null])];
        $this->assertSame($expected, $this->contactsSince($lastId));
    }

    /**
     * 付けられる操作のそれぞれで連絡が届く(連絡する人 = u0010、ひと言 = 操作の名前)。
     */
    public function test_every_operation_that_takes_a_contact_sends_it(): void
    {
        foreach ($this->operationsWithContact() as $name => $operate) {
            $lastId = $this->lastNoticeId();
            [$case, $actor] = $operate(new CaseContact([$this->seededUser('u0010')->id], $name));

            $expected = [$this->caseNotice(WorkflowNoticeType::Contact, 'u0010', $actor, $case, ['message' => $name])];
            $this->assertSame($expected, $this->contactsSince($lastId), $name);
        }
    }

    /**
     * 操作の名前 → 連絡を付けて操作し、[案件, 操作した人のコード] を返すもの。
     *
     * @return array<string, Closure(CaseContact): array{WorkflowCase, string}>
     */
    private function operationsWithContact(): array
    {
        $applied = fn () => $this->applyAs($this->seededUser('u0001'));
        $sentBack = function () use ($applied): WorkflowCase {
            $case = $applied();
            $this->sendBack($this->seededUser('u0002'), $case, 'apply');

            return $case;
        };
        $onApproval = fn (string $class, mixed ...$before) => function (CaseContact $contact) use (
            $applied, $class, $before,
        ): array {
            $case = $applied();
            $nodeId = $this->approvalNodeId($case);
            $this->executeOn($class, 'u0002', $case, $nodeId, ...[...$before, null, null, $contact]);

            return [$case, 'u0002'];
        };
        $byApplicant = fn (string $class, mixed ...$before) => function (CaseContact $contact) use (
            $sentBack, $class, $before,
        ): array {
            $case = $sentBack();
            $this->executeOn($class, 'u0001', $case, ...[...$before, null, $contact]);

            return [$case, 'u0001'];
        };

        return [
            ...$this->applications(),
            ...$this->approvalOperations(),
            '再申請' => $byApplicant(ReapplyCase::class, $this->projectInput()),
            '承認終了' => $onApproval(ApproveCaseEarly::class),
            '否認' => $onApproval(RejectCase::class),
            '取止め' => $byApplicant(WithdrawCase::class, null),
            '保留' => $onApproval(HoldCase::class),
            '保留解除' => function (CaseContact $contact): array {
                $case = $this->applyAs($this->seededUser('u0001'));
                $nodeId = $this->approvalNodeId($case);
                $this->executeOn(HoldCase::class, 'u0002', $case, $nodeId, null);
                $this->executeOn(ReleaseCase::class, 'u0002', $case, $nodeId, null, null, $contact);

                return [$case, 'u0002'];
            },
            '振替' => $onApproval(TransferCase::class, $this->seededUser('u0006')->id),
        ];
    }

    /**
     * 承認・差戻し・引戻し(連絡を付ける)。
     *
     * @return array<string, Closure(CaseContact): array{WorkflowCase, string}>
     */
    private function approvalOperations(): array
    {
        $applied = fn () => $this->applyAs($this->seededUser('u0001'));

        return [
            '承認' => function (CaseContact $contact) use ($applied): array {
                $case = $applied();
                $this->approveWith($case, 'u0002', $contact);

                return [$case, 'u0002'];
            },
            '差戻し' => function (CaseContact $contact) use ($applied): array {
                $case = $applied();
                [$nodeId, $applyNodeId] = [$this->approvalNodeId($case), $this->caseNode($case, 'apply')->id];
                $this->executeOn(SendBackCase::class, 'u0002', $case, $nodeId, $applyNodeId, null, null, $contact);

                return [$case, 'u0002'];
            },
            '引戻し' => function (CaseContact $contact) use ($applied): array {
                $case = $applied();
                $nodeId = $this->pullBackNodeId($case->id, $this->seededUser('u0001')->id);
                $this->executeOn(PullBackCase::class, 'u0001', $case, $nodeId, null, null, $contact);

                return [$case, 'u0001'];
            },
        ];
    }

    /**
     * 新規の申請と一時保存からの申請(連絡を付ける)。
     *
     * @return array<string, Closure(CaseContact): array{WorkflowCase, string}>
     */
    private function applications(): array
    {
        $applicant = $this->contextAs('u0001');

        return [
            '新規の申請' => function (CaseContact $contact) use ($applicant): array {
                $result = app(ApplyCase::class)
                    ->execute($applicant, self::FLOW_CODE, $this->projectInput(), '2026-10-13', null, $contact);

                return [WorkflowCase::query()->findOrFail($result->caseId), 'u0001'];
            },
            '一時保存からの申請' => function (CaseContact $contact) use ($applicant): array {
                $draft = new DraftInput('下書き', null, [], []);
                $caseId = app(CreateDraft::class)->execute($applicant, self::FLOW_CODE, $draft);
                $case = WorkflowCase::query()->findOrFail($caseId);
                $input = $this->projectInput();
                $revision = $case->revision;
                app(ApplyCase::class)->executeFromDraft($applicant, $caseId, $revision, $input, '2026-10-13', $contact);

                return [$case, 'u0001'];
            },
        ];
    }

    /**
     * 案件の操作の業務処理を、その人として今の改訂番号で呼ぶ(続きの引数はそのまま渡す)。
     */
    private function executeOn(string $class, string $userCode, WorkflowCase $case, mixed ...$arguments): void
    {
        app($class)->execute($this->contextAs($userCode), $case->id, $this->revisionOf($case), ...$arguments);
    }

    private function approveWith(
        WorkflowCase $case,
        string $userCode,
        CaseContact $contact,
        ?string $principalCode = null,
    ): void {
        $principal = $principalCode === null ? null : $this->seededUser($principalCode)->id;
        $nodeId = $this->approvalNodeId($case);
        $this->executeOn(ApproveCase::class, $userCode, $case, $nodeId, null, [], $principal, $contact);
    }

    private function approvalNodeId(WorkflowCase $case): int
    {
        return $this->caseNode($case, 'approval1')->id;
    }

    private function contextAs(string $userCode): AccessContext
    {
        return $this->contextOf($this->seededUser($userCode));
    }

    /**
     * @return list<array{string, string, string|null, string|null, array<string, mixed>}>
     */
    private function contactsSince(int $lastId): array
    {
        $isContact = fn (array $line) => $line[0] === WorkflowNoticeType::Contact->value;
        $lines = array_filter($this->noticesSince($lastId), $isContact);

        return array_values($lines);
    }
}
