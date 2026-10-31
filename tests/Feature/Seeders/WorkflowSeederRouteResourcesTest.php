<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの初期データのうち、接頭辞のリソースグループとルートのリソース(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 5 節、
 * 設計/ワークフロー/案件の運用/初期データ.md の 5 節)。ほかの初期データは {@see WorkflowSeederTest}。
 * 初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederRouteResourcesTest extends TransactionalTestCase
{
    /** 申請操作の接頭辞のリソースグループ */
    private const ADMIN_CASES = 'http-services.workflow.admin.cases';

    /** 代理の管理の接頭辞のリソースグループ */
    private const ADMIN_PROXIES = 'http-services.workflow.admin.proxies';

    /** 定義の管理の接頭辞のリソースグループ */
    private const ADMIN_FLOWS = 'http-services.workflow.admin.flows';

    private const ADMIN_VERSIONS = 'http-services.workflow.admin.versions';

    /** ワークフローの接頭辞のリソースグループとルートのリソース(作った順。出典は下のテストの注): [ID, 親の ID, 表示名 ja, en] */
    private const ROUTE_RESOURCE_GROUPS = [
        ['http-services.workflow', 'http-services', 'ワークフロー', 'Workflow'],
        ['http-services.workflow.flows', 'http-services.workflow', '申請できるフロー', 'Applicable flows'],
        ['http-services.workflow.applications', 'http-services.workflow', '新規申請', 'New applications'],
        ['http-services.workflow.drafts', 'http-services.workflow', '一時保存', 'Drafts'],
        ['http-services.workflow.cases', 'http-services.workflow', '申請', 'Applications'],
        ['http-services.workflow.reapplications', 'http-services.workflow', '再申請', 'Reapplications'],
        ['http-services.workflow.proxies', 'http-services.workflow', '代理の設定', 'Proxy settings'],
        ['http-services.workflow.admin', 'http-services.workflow', 'ワークフローの管理', 'Workflow administration'],
        [self::ADMIN_CASES, 'http-services.workflow.admin', '申請操作', 'Application operations'],
        [self::ADMIN_PROXIES, 'http-services.workflow.admin', '代理の管理', 'Proxy administration'],
        [self::ADMIN_FLOWS, 'http-services.workflow.admin', 'フロー定義', 'Flow definitions'],
        ['http-services.workflow.admin.flows.referrers', self::ADMIN_FLOWS, '参照者', 'Referrers'],
        [self::ADMIN_VERSIONS, 'http-services.workflow.admin', 'フロー定義の版', 'Flow definition versions'],
        ['http-services.workflow.admin.nodes', 'http-services.workflow.admin', 'ステップの設定', 'Step settings'],
        ['http-services.workflow.flows.index', 'http-services.workflow.flows', '申請できるフローの一覧', 'Applicable flow list'],
        [
            'http-services.workflow.applications.create', 'http-services.workflow.applications', '申請の画面',
            'New application form',
        ],
        ['http-services.workflow.applications.store', 'http-services.workflow.applications', '申請', 'Apply'],
        ['http-services.workflow.drafts.store', 'http-services.workflow.drafts', '一時保存', 'Save draft'],
        ['http-services.workflow.drafts.edit', 'http-services.workflow.drafts', '一時保存の画面', 'Draft form'],
        ['http-services.workflow.drafts.update', 'http-services.workflow.drafts', '一時保存の上書き', 'Update draft'],
        ['http-services.workflow.drafts.apply', 'http-services.workflow.drafts', '一時保存からの申請', 'Apply from draft'],
        ['http-services.workflow.drafts.destroy', 'http-services.workflow.drafts', '一時保存の削除', 'Delete draft'],
        ['http-services.workflow.cases.index', 'http-services.workflow.cases', '申請一覧', 'Application list'],
        ['http-services.workflow.cases.show', 'http-services.workflow.cases', '申請の詳細', 'Application detail'],
        ['http-services.workflow.cases.approve', 'http-services.workflow.cases', '承認', 'Approve'],
        ['http-services.workflow.cases.approve-early', 'http-services.workflow.cases', '承認終了', 'Approve and close'],
        ['http-services.workflow.cases.reject', 'http-services.workflow.cases', '否認', 'Reject'],
        ['http-services.workflow.cases.send-back', 'http-services.workflow.cases', '差戻し', 'Send back'],
        ['http-services.workflow.cases.pull-back', 'http-services.workflow.cases', '引戻し', 'Pull back'],
        ['http-services.workflow.cases.withdraw', 'http-services.workflow.cases', '取止め', 'Withdraw'],
        [
            'http-services.workflow.cases.assignee-candidates', 'http-services.workflow.cases', '処理対象者の候補',
            'Assignee candidates',
        ],
        [
            'http-services.workflow.reapplications.create', 'http-services.workflow.reapplications', '再申請の画面',
            'Reapplication form',
        ],
        ['http-services.workflow.reapplications.store', 'http-services.workflow.reapplications', '再申請', 'Reapply'],
        ['http-services.workflow.cases.bulk-approve', 'http-services.workflow.cases', '一括承認', 'Bulk approve'],
        ['http-services.workflow.cases.bulk-confirm', 'http-services.workflow.cases', '一括確認', 'Bulk confirm'],
        ['http-services.workflow.user-candidates', 'http-services.workflow', '利用者の候補', 'User candidates'],
        ['http-services.workflow.cases.hold', 'http-services.workflow.cases', '保留', 'Hold'],
        ['http-services.workflow.cases.release', 'http-services.workflow.cases', '保留解除', 'Release hold'],
        ['http-services.workflow.cases.transfer', 'http-services.workflow.cases', '担当変更', 'Transfer'],
        ['http-services.workflow.cases.confirm', 'http-services.workflow.cases', '確認', 'Confirm'],
        ['http-services.workflow.target-values', 'http-services.workflow', '申請対象の値', 'Target values'],
        ['http-services.workflow.record-candidates', 'http-services.workflow', '参照項目の候補', 'Record candidates'],
        ['http-services.workflow.proxies.index', 'http-services.workflow.proxies', '代理の設定', 'Proxy settings'],
        ['http-services.workflow.proxies.store', 'http-services.workflow.proxies', '代理の追加', 'Add proxy'],
        ['http-services.workflow.proxies.destroy', 'http-services.workflow.proxies', '代理の削除', 'Delete proxy'],
        ['http-services.workflow.admin.cases.index', self::ADMIN_CASES, '申請操作の一覧', 'Application operation list'],
        ['http-services.workflow.admin.cases.retarget', self::ADMIN_CASES, '処理担当者の変更', 'Change assignees'],
        ['http-services.workflow.admin.cases.reexpand', self::ADMIN_CASES, '再展開', 'Re-expand assignees'],
        ['http-services.workflow.admin.cases.move', self::ADMIN_CASES, 'ステップの移動', 'Move step'],
        ['http-services.workflow.admin.cases.assign', self::ADMIN_CASES, '指定の変更', 'Change designation'],
        ['http-services.workflow.admin.cases.destroy', self::ADMIN_CASES, '申請の削除', 'Delete application'],
        ['http-services.workflow.admin.proxies.index', self::ADMIN_PROXIES, '代理の管理', 'Proxy administration'],
        ['http-services.workflow.admin.proxies.store', self::ADMIN_PROXIES, '代理の追加(管理)', 'Add proxy (admin)'],
        ['http-services.workflow.admin.proxies.destroy', self::ADMIN_PROXIES, '代理の削除(管理)', 'Delete proxy (admin)'],
        ['http-services.workflow.admin.flows.index', self::ADMIN_FLOWS, 'フロー定義の一覧', 'Flow definition list'],
        ['http-services.workflow.admin.flows.show', self::ADMIN_FLOWS, 'フロー定義の詳細', 'Flow definition detail'],
        [
            'http-services.workflow.admin.flows.referrers.update', 'http-services.workflow.admin.flows.referrers',
            '参照者の保存', 'Save referrers',
        ],
        ['http-services.workflow.admin.versions.store', self::ADMIN_VERSIONS, '版の追加', 'Add version'],
        ['http-services.workflow.admin.versions.disable', self::ADMIN_VERSIONS, '版の無効化', 'Disable version'],
        ['http-services.workflow.admin.versions.enable', self::ADMIN_VERSIONS, '版の有効化', 'Enable version'],
        [
            'http-services.workflow.admin.nodes.update', 'http-services.workflow.admin.nodes', 'ステップの設定の保存',
            'Save step settings',
        ],
        [
            'http-services.workflow.admin.subject-candidates', 'http-services.workflow.admin', '対象者の候補',
            'Subject candidates',
        ],
    ];

    /**
     * 設計/ワークフロー/ルートの深さ/初期データ.md 5 節: 接頭辞のリソースグループ 14 とルートのリソース 48(ルート 18 本 =
     * 設計/ワークフロー/認可と一覧.md 4 節と、処理対象者の候補と、一括承認・一括確認・利用者の候補・保留・保留解除・振替・確認・参照項目の候補・
     * 代理の設定と申請操作と代理の管理の 12 本 = 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節と、写しの値の入口 =
     * 設計/ワークフロー/申請の周辺/初期データ.md の 3 節。申請操作・代理の管理の親 http-services.workflow.admin の表示名 =
     * 設計/ワークフロー/ルートの深さ/認可と一覧.md の 4.2 節。定義の管理の接頭辞 4 とルート 8 = 同じ 4.2 節。ID・親は名前から決まる。
     * 表示名 ja・en = それぞれの節)。
     * ポリシー 62 は WorkflowRouteAuthorizationTest・AuthorizationSeedersTest が確かめる(申請操作・定義の管理はポリシーなし)。
     */
    public function test_seeds_the_route_resources_of_the_design(): void
    {
        $translated = fn (AuthzResourceGroup $group, string $locale) => $group->authzResourceGroupTranslations
            ->firstOrFail(fn (AuthzResourceGroupTranslation $translation) => $translation->locale?->code === $locale)
            ->display_name;
        $groups = AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations.locale'])
            ->where('code', 'like', 'http-services.workflow%')->orderBy('id')->get()
            ->map(fn (AuthzResourceGroup $group) => [
                $group->code,
                $group->parent?->code,
                $translated($group, 'ja'),
                $translated($group, 'en'),
            ])
            ->all();

        $this->assertSame(self::ROUTE_RESOURCE_GROUPS, $groups);
        $this->assertSame(48, AuthzResource::query()->where('uri', 'like', 'service://workflow/%')->count());
    }
}
