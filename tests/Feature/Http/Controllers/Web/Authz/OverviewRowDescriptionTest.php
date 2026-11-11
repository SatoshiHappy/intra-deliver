<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Models\Master\User;
use App\Queries\Authz\OverviewTree;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の行の説明(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.2 の行の description と 2.3 の先頭の列、
 * 設計/メニュー/認可との結び付け/ルートのリソース.md 2.3 の束の規則 4): 初期データでは画面・処理のルートの束 8 つだけが説明を持ち、フォルダと先頭とほかの種類の行は null。
 * 説明はロケールの行のもの。初期データを入れ、システム管理者(u0004)で開く。
 */
final class OverviewRowDescriptionTest extends TransactionalTestCase
{
    use ReadsAuthzGrid;

    private const URL = '/authz/overview';

    /** ルートの束 => 説明(ja) */
    private const BUNDLES = [
        'http-services.authz.settings' => '認可対象ごとの許可と禁止を表示し、変更できます',
        'http-services.master.organization-chart' => '組織の階層と、組織に所属するユーザーを表示できます',
        'http-services.records.admin.categories' => 'モジュール分類を作成・変更し、業務モジュールを分類できます',
        'http-services.records.admin.comments' => 'すべての業務モジュールで、ほかの人のコメントも削除できます',
        'http-services.records.admin.modules' => '業務モジュールを作成し、項目・見出し・規則などの定義を変更できます',
        'http-services.workflow.admin.cases' => '参照を許されたフローの申請を一覧表示し、'
            .'操作を許されたフローでは処理担当者の変更・ステップの移動・削除などができます',
        'http-services.workflow.admin.flows' => 'フロー定義の参照者・添付ファイルの設定・版・ステップの設定を変更できます',
        'http-services.workflow.admin.proxies' => 'すべてのユーザーの代理を一覧表示し、追加・削除できます',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_only_the_bundles_of_the_service_grid_have_descriptions(): void
    {
        $rows = $this->grid($this->get(self::URL))['rows'];

        $this->assertSame(
            [...array_fill_keys(array_column($rows, 'code'), null), ...self::BUNDLES],
            array_column($rows, 'description', 'code'),
        );
    }

    #[TestWith(['im-menu-group'])]
    #[TestWith(['record-module'])]
    #[TestWith(['workflow-flow'])]
    public function test_the_rows_of_the_other_types_have_no_description(string $resourceType): void
    {
        $rows = $this->grid($this->get(self::URL."?type={$resourceType}"))['rows'];

        $this->assertNotEmpty($rows);
        $this->assertSame([], array_filter(array_column($rows, 'description')));
    }

    public function test_the_description_follows_the_locale(): void
    {
        $nodes = app(OverviewTree::class)->nodesOf('service', 'en');
        $descriptions = [];

        foreach ($nodes as $node) {
            $descriptions[$node->code] = $node->description;
        }

        $this->assertSame(
            'List the applications of the flows you may view,'
                .' and change assignees, move steps, delete applications and more in the flows you may operate',
            $descriptions['http-services.workflow.admin.cases'],
        );
        $this->assertNull($descriptions['http-services.workflow']);
    }
}
