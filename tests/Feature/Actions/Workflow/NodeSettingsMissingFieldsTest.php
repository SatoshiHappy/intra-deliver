<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\FlowDefinitionErrors;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の欠けた欄(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.1 節の入力の欄)と、
 * 処理期限・確認する人を持たない種類(設計/ワークフロー/案件の運用/定義の管理.md の 1 節・5 節): 種類が持てる欄(必須・任意)が送られて
 * いなければ、その欄のキーと文の 422 で何も変わらない。同じ欄を null・false・空の配列で送れば空で保存する。禁止の欄は欠けてもよく、値があれば
 * 422。版 = R1(申請 apply・承認 section_chief・縦配置 council・分岐 budget)と横配置の版(family)。期待の文言は 6 節・5 節の表から書いた。
 */
final class NodeSettingsMissingFieldsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const NOT_ALLOWED = 'このステップには、この設定はできません';

    private const PREVENTS = '申請者本人の承認を防ぐ設定を選択してください';

    private const ATTACHMENT = '添付ファイルの追加の設定を選択してください';

    private const DEADLINE = '処理期限は 0 から 99 までの日数を入力してください';

    private const CONFIRMER = '確認する人を選択してください';

    /** @var array<string, int> 版の名前 → 版の id */
    private array $versions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versions = [
            'r1' => $this->addVersion($this->r1()),
            'serial' => $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial), '2026-06-01'),
        ];
    }

    /**
     * @return array<string, array{string, string, string, string}> [版, ノード, 欠けた欄, 文言]
     */
    public static function missingFields(): array
    {
        return [
            '申請・指定の種類' => ['r1', 'apply', 'target_kind', '指定の種類を選択してください'],
            '申請・対象者' => ['r1', 'apply', 'targets', '対象者を 1 件以上指定してください'],
            '申請・確認する人' => ['r1', 'apply', 'confirmers', self::CONFIRMER],
            '承認・役職' => ['r1', 'section_chief', 'company_post_id', '役職を選択してください'],
            '承認・申請者本人の承認を防ぐ' => ['r1', 'section_chief', 'prevents_applicant_approval', self::PREVENTS],
            '承認・差戻しできる先' => ['r1', 'section_chief', 'send_back_node_codes', FlowDefinitionErrors::SEND_BACK_INVALID],
            '承認・添付ファイル' => ['r1', 'section_chief', 'prevents_attachment_add', self::ATTACHMENT],
            '承認・処理期限' => ['r1', 'section_chief', 'deadline_days', self::DEADLINE],
            '承認・確認する人' => ['r1', 'section_chief', 'confirmers', self::CONFIRMER],
            '横配置・申請者本人の承認を防ぐ' => ['serial', 'family', 'prevents_applicant_approval', self::PREVENTS],
            '横配置・差戻しできる先' => ['serial', 'family', 'send_back_node_codes', FlowDefinitionErrors::SEND_BACK_INVALID],
            '横配置・添付ファイル' => ['serial', 'family', 'prevents_attachment_add', self::ATTACHMENT],
            '横配置・処理期限' => ['serial', 'family', 'deadline_days', self::DEADLINE],
            '縦配置・申請者本人の承認を防ぐ' => ['r1', 'council', 'prevents_applicant_approval', self::PREVENTS],
            '縦配置・差戻しできる先' => ['r1', 'council', 'send_back_node_codes', FlowDefinitionErrors::SEND_BACK_INVALID],
            '縦配置・添付ファイル' => ['r1', 'council', 'prevents_attachment_add', self::ATTACHMENT],
            '縦配置・処理期限' => ['r1', 'council', 'deadline_days', self::DEADLINE],
            '縦配置・可決の条件' => ['r1', 'council', 'decision_rule', '可決の条件を選択してください'],
        ];
    }

    /**
     * 種類が持てる欄を 1 つ外すと、その欄のキーと文の 422 で何も変わらない(任意の欄も、空で上書きしない)。
     */
    #[DataProvider('missingFields')]
    public function test_a_missing_field_the_kind_has_is_rejected(
        string $version,
        string $nodeCode,
        string $field,
        string $message,
    ): void {
        $missing = [Str::camel($field) => null, 'missing' => [$field]];

        $this->assertErrors([$field => $message], $version, $nodeCode, $missing);
    }

    /**
     * 同じ欄を null・false・空の配列で送れば、今のまま空(オフ・なし)で保存する。
     */
    public function test_the_same_fields_sent_empty_are_saved_empty(): void
    {
        $this->saveSettings($this->versions['r1'], 'section_chief', [
            'companyPostId' => null, 'preventsApplicantApproval' => false, 'sendBackNodeCodes' => [],
            'preventsAttachmentAdd' => null, 'deadlineDays' => null, 'confirmers' => [],
        ]);

        $node = $this->routeNode($this->versions['r1'], 'section_chief');
        $this->assertSame([null, false, false, null, null], [
            $node->company_post_id, $node->prevents_applicant_approval, $node->prevents_attachment_add,
            $node->deadline_days, $node->confirm_authz_subject_group_id,
        ]);
        $this->assertFalse(WorkflowRouteSendBack::query()->where('workflow_route_node_id', $node->id)->exists());
    }

    /**
     * 種類が持たない欄(禁止)は欠けてもよい(申請の期限・横配置の確認する人・分岐のすべての欄)。ノードの列は変わらない
     * (差戻しできる先の行は入れ直すので比べない)。
     */
    public function test_a_missing_field_the_kind_does_not_have_is_accepted(): void
    {
        $before = $this->settingsSnapshot()['nodes'];

        $this->saveSettings($this->versions['r1'], 'apply', [
            'deadlineDays' => null, 'preventsApplicantApproval' => null, 'sendBackNodeCodes' => null,
            'missing' => ['deadline_days', 'prevents_applicant_approval', 'send_back_node_codes', 'decision_rule'],
        ]);
        $this->saveSettings($this->versions['serial'], 'family', ['confirmers' => null, 'missing' => ['confirmers']]);
        $this->saveSettings($this->versions['r1'], 'budget', [
            'targetKind' => null, 'preventsApplicantApproval' => null, 'sendBackNodeCodes' => null,
            'missing' => [
                'target_kind', 'targets', 'prevents_applicant_approval', 'send_back_node_codes', 'confirmers',
            ],
        ]);

        $this->assertSame($before, $this->settingsSnapshot()['nodes']);
    }

    /**
     * 期限・確認する人を持たない種類に値を送ると、その欄の 422(期限 = 申請、確認する人 = 横配置・縦配置、どちらも = 分岐)。
     */
    public function test_the_deadline_and_the_confirmers_on_a_kind_without_them_are_rejected(): void
    {
        $user = [$this->seededUser('u0002')->id];

        $this->assertErrors(['deadline_days' => self::NOT_ALLOWED], 'r1', 'apply', ['deadlineDays' => 3]);
        $this->assertErrors(['confirmers' => self::NOT_ALLOWED], 'serial', 'family', ['confirmers' => $user]);
        $this->assertErrors(['confirmers' => self::NOT_ALLOWED], 'r1', 'council', ['confirmers' => $user]);
        $this->assertErrors(
            ['deadline_days' => self::NOT_ALLOWED, 'confirmers' => self::NOT_ALLOWED],
            'r1', 'budget', ['deadlineDays' => 0, 'confirmers' => $user],
        );
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, mixed>  $overrides
     */
    private function assertErrors(array $expected, string $version, string $nodeCode, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $versionId = $this->versions[$version];
        $errors = $this->validationErrors(fn () => $this->saveSettings($versionId, $nodeCode, $overrides));

        ksort($expected);
        ksort($errors);
        $this->assertSame($expected, $errors, $nodeCode);
        $this->assertSame($before, $this->settingsSnapshot());
    }
}
