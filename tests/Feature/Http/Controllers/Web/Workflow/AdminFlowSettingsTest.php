<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Http\Requests\Workflow\FlowSettingsRequest;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * フローの機能設定の保存 workflow.admin.flows.settings.update(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節): システム管理者が
 * 添付ファイルの「使用しない」「使用する」を保存でき、詳細のタブ「設定」へ 303 とトースト。型の誤りは 422、無いフローは 404、
 * ルートの認可(ゲスト・一般の利用者の 403 と 404 の順)は AdminFlowDefinitionControllerTest の 8 ルートの組が見る。効き目 = 保存の後の新しい申請の
 * 画面は添付の面を出さず、申請中の案件は申請した時の値のまま(読み取りの規則は CaseAttachabilityTest・ApplyAttachmentsTest)。
 */
final class AdminFlowSettingsTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;
    use UsesSeededCases;

    private const SETTINGS_URL = '/workflow/admin/flows/'.self::PLAN.'/settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAsUser(self::ADMIN);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function values(): array
    {
        return ['使用しない' => [false], '使用する' => [true]];
    }

    #[DataProvider('values')]
    public function test_the_administrator_saves_the_value_and_lands_on_the_settings_tab(bool $enabled): void
    {
        $this->flow()->update(['is_attachment_enabled' => ! $enabled]);

        $response = $this->put(self::SETTINGS_URL, ['is_attachment_enabled' => $enabled], ['X-Inertia' => 'true']);

        $response->assertStatus(303)->assertSessionHasNoErrors()
            ->assertRedirect(url('/workflow/admin/flows/'.self::PLAN.'?tab=settings'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '添付ファイルの設定を保存しました']);
        $this->assertSame($enabled, $this->flow()->is_attachment_enabled);
        $this->get('/workflow/admin/flows/'.self::PLAN.'?tab=settings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workflow/Admin/FlowShow')
                ->where('flowSettings.isAttachmentEnabled', $enabled));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function brokenInputs(): array
    {
        return [
            '無い' => [[]], 'null' => [['is_attachment_enabled' => null]],
            '真偽でない文字' => [['is_attachment_enabled' => 'x']], '並び' => [['is_attachment_enabled' => [true]]],
            '数' => [['is_attachment_enabled' => 2]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('brokenInputs')]
    public function test_a_missing_or_non_boolean_value_is_unprocessable_and_changes_nothing(array $data): void
    {
        $before = $this->definitionSnapshot();

        $message = FlowSettingsRequest::IS_ATTACHMENT_ENABLED_INVALID;

        $this->sendJson('put', self::SETTINGS_URL, $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['is_attachment_enabled' => $message]);

        $this->assertSame($before, $this->definitionSnapshot());
    }

    public function test_a_missing_flow_is_not_found(): void
    {
        $this->sendJson('put', '/workflow/admin/flows/missing_flow/settings', ['is_attachment_enabled' => 'x'])
            ->assertNotFound();
    }

    /**
     * 保存の後の新しい申請の画面は添付の面を出さず(使う側の設定を見る)、申請中の案件の写しは変わらない。
     */
    public function test_the_value_applies_to_new_applications_and_not_to_applied_cases(): void
    {
        $this->flow()->update(['is_attachment_enabled' => true]);
        $applied = WorkflowCase::query()->whereNotNull('is_attachment_enabled')->where('is_attachment_enabled', true)
            ->orderBy('id')->firstOrFail();
        $this->actingAsUser('u0001');
        $this->get('/workflow/flows/'.self::PLAN.'/apply')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attachment.enabled', true));

        $this->actingAsUser(self::ADMIN);
        $this->put(self::SETTINGS_URL, ['is_attachment_enabled' => false])->assertRedirect();

        $this->actingAsUser('u0001');
        $this->get('/workflow/flows/'.self::PLAN.'/apply')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attachment.enabled', false));
        $this->assertTrue($applied->refresh()->is_attachment_enabled);
    }

    private function flow(): WorkflowFlow
    {
        return WorkflowFlow::query()->where('code', self::PLAN)->sole();
    }
}
