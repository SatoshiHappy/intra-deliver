<?php

namespace Database\Seeders;

use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;

/**
 * {@see WorkflowSeeder} のルートの初期データ(接頭辞のリソースグループと、ルートのリソース・ポリシー)。
 * 使う側は createPrefix・createRouteResource・createPolicy・findOrCreate を持つ。
 */
trait SeedsWorkflowRoutes
{
    use FindsAuthzSubjects;
    use TranslatesRoutePrefixes;

    /** 設定不可のセットの接頭辞のリソースグループ(利用者側のルートの上): 接頭辞 => [表示名(ja), 表示名(en)] */
    private const PREFIXES = [
        'workflow' => ['ワークフロー', 'Workflow'],
        'workflow.flows' => ['申請できるフロー', 'Applicable flows'],
        'workflow.applications' => ['新規申請', 'New applications'],
        'workflow.drafts' => ['一時保存', 'Drafts'],
        'workflow.cases' => ['申請', 'Applications'],
        'workflow.reapplications' => ['再申請', 'Reapplications'],
        'workflow.proxies' => ['代理の設定', 'Proxy settings'],
    ];

    /** http-services の接頭辞のリソースグループ(管理のルートの上のフォルダとルートの束): 接頭辞 => [表示名(ja), 表示名(en), 説明(ja)?, 説明(en)?] */
    private const ADMIN_PREFIXES = [
        'workflow' => ['ワークフロー', 'Workflow'],
        'workflow.admin' => ['ワークフローの管理', 'Workflow administration'],
        'workflow.admin.cases' => [
            '申請操作', 'Application operations',
            '参照を許されたフローの申請を一覧表示し、操作を許されたフローでは処理担当者の変更・ステップの移動・削除などができます',
            'List the applications of the flows you may view,'
                .' and change assignees, move steps, delete applications and more in the flows you may operate',
        ],
        'workflow.admin.proxies' => [
            '代理の管理', 'Proxy administration',
            'すべてのユーザーの代理を一覧表示し、追加・削除できます',
            'List, add and delete the proxies of every user',
        ],
        'workflow.admin.flows' => [
            'フロー定義', 'Flow definitions',
            'フロー定義の参照者・添付ファイルの設定・版・ステップの設定を変更できます',
            'Change the referrers, attachment settings, versions and steps of flow definitions',
        ],
    ];

    /** ルート: 名前 => [表示名(ja), 表示名(en)](どれもゲストユーザと認証済みユーザに execute を許す) */
    private const ROUTES = [
        // ワークフローのホーム(docs の 設計/ワークフロー/認可と一覧.md 4 節、設計/メニュー/ホーム.md 6.7)
        'workflow.home' => ['ワークフローのホーム', 'Workflow home'],
        'workflow.flows.index' => ['申請できるフローの一覧', 'Applicable flow list'],
        'workflow.applications.create' => ['申請の画面', 'New application form'],
        'workflow.applications.store' => ['申請', 'Apply'],
        'workflow.drafts.store' => ['一時保存', 'Save draft'],
        'workflow.drafts.edit' => ['一時保存の画面', 'Draft form'],
        'workflow.drafts.update' => ['一時保存の上書き', 'Update draft'],
        'workflow.drafts.apply' => ['一時保存からの申請', 'Apply from draft'],
        'workflow.drafts.destroy' => ['一時保存の削除', 'Delete draft'],
        'workflow.cases.index' => ['申請一覧', 'Application list'],
        'workflow.cases.show' => ['申請の詳細', 'Application detail'],
        'workflow.cases.approve' => ['承認', 'Approve'],
        'workflow.cases.approve-early' => ['承認終了', 'Approve and close'],
        'workflow.cases.reject' => ['否認', 'Reject'],
        'workflow.cases.send-back' => ['差戻し', 'Send back'],
        'workflow.cases.pull-back' => ['引戻し', 'Pull back'],
        'workflow.cases.withdraw' => ['取止め', 'Withdraw'],
        'workflow.cases.assignee-candidates' => ['承認者の候補', 'Assignee candidates'],
        'workflow.reapplications.create' => ['再申請の画面', 'Reapplication form'],
        'workflow.reapplications.store' => ['再申請', 'Reapply'],
        'workflow.cases.bulk-approve' => ['一括承認', 'Bulk approve'],
        'workflow.cases.bulk-confirm' => ['一括確認', 'Bulk confirm'],
        'workflow.user-candidates' => ['利用者の候補', 'User candidates'],
        'workflow.cases.hold' => ['保留', 'Hold'],
        'workflow.cases.release' => ['保留解除', 'Release hold'],
        'workflow.cases.transfer' => ['担当変更', 'Transfer'],
        'workflow.cases.confirm' => ['確認', 'Confirm'],
        'workflow.target-values' => ['申請対象の値', 'Target values'],
        'workflow.record-candidates' => ['参照項目の候補', 'Record candidates'],
        'workflow.proxies.index' => ['代理の設定', 'Proxy settings'],
        'workflow.proxies.store' => ['代理の追加', 'Add proxy'],
        'workflow.proxies.destroy' => ['代理の削除', 'Delete proxy'],
    ];

    /**
     * システム管理者だけのルート: 名前 => [表示名(ja), 表示名(en)](ポリシーを置かず、システム管理者がセットの先頭の許可を継承する =
     * 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節。定義の管理 = 設計/ワークフロー/ルートの深さ/認可と一覧.md の 4.2 節)
     */
    private const ADMIN_ROUTES = [
        'workflow.admin.cases.index' => ['申請操作の一覧', 'Application operation list'],
        'workflow.admin.cases.retarget' => ['処理担当者の変更', 'Change assignees'],
        'workflow.admin.cases.reexpand' => ['再展開', 'Re-expand assignees'],
        'workflow.admin.cases.move' => ['ステップの移動', 'Move step'],
        'workflow.admin.cases.assign' => ['指定の変更', 'Change designation'],
        'workflow.admin.cases.destroy' => ['申請の削除', 'Delete application'],
        'workflow.admin.proxies.index' => ['代理の管理', 'Proxy administration'],
        'workflow.admin.proxies.store' => ['代理の追加(管理)', 'Add proxy (admin)'],
        'workflow.admin.proxies.destroy' => ['代理の削除(管理)', 'Delete proxy (admin)'],
        'workflow.admin.flows.index' => ['フロー定義の一覧', 'Flow definition list'],
        'workflow.admin.flows.show' => ['フロー定義の詳細', 'Flow definition detail'],
        'workflow.admin.flows.update-referrers' => ['参照者の保存', 'Save referrers'],
        'workflow.admin.flows.update-settings' => ['添付ファイルの設定の保存', 'Save attachment settings'],
        'workflow.admin.flows.store-version' => ['版の追加', 'Add version'],
        'workflow.admin.flows.disable-version' => ['版の無効化', 'Disable version'],
        'workflow.admin.flows.enable-version' => ['版の有効化', 'Enable version'],
        'workflow.admin.flows.update-node' => ['ステップの設定の保存', 'Save step settings'],
        'workflow.admin.flows.subject-candidates' => ['対象者の候補', 'Subject candidates'],
    ];

    /**
     * 接頭辞のリソースグループと、ルートのリソース・ポリシー(RecordsSeeder の業務のルートと同じ書き方)。システム管理者だけのルートはリソースだけ。
     */
    private function createRoutes(): void
    {
        $prefixesOfSets = [
            ServiceAction::UNMANAGED_SET_CODE => self::PREFIXES,
            ServiceAction::SET_CODE => self::ADMIN_PREFIXES,
        ];

        foreach ($prefixesOfSets as $set => $prefixes) {
            foreach ($prefixes as $prefix => $names) {
                $this->createPrefix->execute($prefix, $set, $this->prefixTranslations($names));
            }
        }

        $everyone = [
            $this->guestSubjectGroupId($this->findOrCreate),
            $this->authenticatedSubjectGroupId($this->findOrCreate),
        ];

        foreach (self::ROUTES as $name => [$ja, $en]) {
            $names = ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]];
            $resource = $this->createRouteResource->execute($name, $names);

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute(
                    $resource->authz_resource_group_id,
                    $subjectGroupId,
                    ServiceAction::RESOURCE_TYPE,
                    ServiceAction::Execute->value,
                    PolicyEffect::Permit,
                );
            }
        }

        foreach (self::ADMIN_ROUTES as $name => [$ja, $en]) {
            $names = ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]];
            $this->createRouteResource->execute($name, $names);
        }
    }
}
