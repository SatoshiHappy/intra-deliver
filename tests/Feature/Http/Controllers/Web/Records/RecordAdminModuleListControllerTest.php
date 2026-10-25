<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの一覧と作成の画面(docs の 設計/業務データの入れ物/画面/管理の画面.md 3 節): システム管理者(u0004)が開く。一覧は親の無い業務モジュールを
 * 並びの順(sort_key・コードの順)に、明細はその親の直後に出し、定義の画面の切替は一覧と同じ並び・同じ範囲。管理のルートの 403 と書き込みは
 * {@see RecordAdminControllerTest}。初期データを入れ、ルートの認可を外さずに開く。
 */
final class RecordAdminModuleListControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_system_administrator_opens_the_module_list_in_the_order_of_the_design(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->get('/records/admin/modules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleIndex')
            ->where('modules', [
                [
                    'code' => 'issue_resolution_application', 'name' => '課題の対応の申請',
                    'parentCode' => null, 'isVisible' => true,
                ],
                ['code' => 'meeting', 'name' => '会議', 'parentCode' => null, 'isVisible' => true],
                ['code' => 'project', 'name' => 'プロジェクト', 'parentCode' => null, 'isVisible' => true],
                ['code' => 'project_milestone', 'name' => 'マイルストーン', 'parentCode' => 'project', 'isVisible' => null],
                ['code' => 'project_issue', 'name' => '課題', 'parentCode' => null, 'isVisible' => true],
                [
                    'code' => 'project_plan_application', 'name' => 'プロジェクト計画の申請',
                    'parentCode' => null, 'isVisible' => true,
                ],
                [
                    'code' => 'project_plan_application_milestones', 'name' => 'マイルストーン',
                    'parentCode' => 'project_plan_application', 'isVisible' => null,
                ],
            ])
        );
    }

    public function test_the_system_administrator_opens_the_create_page_with_the_icons_of_the_choices(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->get('/records/admin/modules/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleCreate')
            ->where('textMaxLengths', ['code' => 50, 'name' => 1000, 'description' => 4000])
            ->where('icons', [
                ['value' => 'table', 'label' => '表'],
                ['value' => 'folder-kanban', 'label' => 'かんばんのフォルダ'],
                ['value' => 'list-todo', 'label' => 'チェックリスト'],
                ['value' => 'clipboard-list', 'label' => 'クリップボード'],
                ['value' => 'circle-alert', 'label' => '注意'],
                ['value' => 'users', 'label' => '人々'],
                ['value' => 'building-2', 'label' => '建物'],
                ['value' => 'briefcase', 'label' => 'かばん'],
                ['value' => 'calendar', 'label' => 'カレンダー'],
                ['value' => 'file-text', 'label' => '文書'],
                ['value' => 'package', 'label' => '荷物'],
                ['value' => 'truck', 'label' => 'トラック'],
                ['value' => 'shopping-cart', 'label' => 'カート'],
                ['value' => 'receipt', 'label' => '領収書'],
                ['value' => 'wrench', 'label' => '工具'],
                ['value' => 'book-open', 'label' => '本'],
                ['value' => 'mail', 'label' => 'メール'],
                ['value' => 'phone', 'label' => '電話'],
                ['value' => 'chart-bar', 'label' => '棒グラフ'],
                ['value' => 'tag', 'label' => 'タグ'],
                ['value' => 'map-pin', 'label' => '地図のピン'],
                ['value' => 'ticket', 'label' => 'チケット'],
                ['value' => 'graduation-cap', 'label' => '学位帽'],
                ['value' => 'heart-pulse', 'label' => '心拍'],
                ['value' => 'coins', 'label' => 'コイン'],
            ])
        );
    }

    public function test_the_definition_carries_the_modules_of_the_switch_in_the_order_of_the_list(): void
    {
        $this->actingAs($this->user('u0004'));
        $rows = $this->props($this->get('/records/admin/modules'))['modules'];
        $listed = array_map(fn (array $row) => ['code' => $row['code'], 'name' => $row['name']], $rows);

        $switch = $this->props($this->get('/records/admin/modules/project_milestone'))['modules'];

        $this->assertGreaterThanOrEqual(3, count($listed));
        $this->assertSame($listed, $switch);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
