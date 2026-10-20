<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 登録・更新・削除の後の移り先(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2)。書き込みの後に移り先を
 * GET し、403 にならずにその画面が開くことまで確かめる(書き込みだけを許された利用者が、成功した後に 403 を見ない)。
 * 初期データを入れ、今日を 2026-10-11 に固定し、ルートの認可を外さずに開く。許可は課題(project_issue)の 4 操作から組を残して消す。
 */
final class RecordWriteDestinationTest extends TransactionalTestCase
{
    use UsesSeededRecords;

    private const MESSAGES = ['store' => '登録しました', 'update' => '更新しました', 'destroy' => '削除しました'];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    /**
     * @param  'store'|'update'|'destroy'  $operation
     * @param  list<RecordModuleAction>  $granted
     * @param  'show'|'index'|'home'  $destination
     */
    #[DataProvider('operationsAndGrants')]
    public function test_the_write_lands_on_the_first_screen_the_user_may_open(string $operation, array $granted, string $destination, string $component): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->grantOnly($granted);
        $count = $this->recordCountOf('project_issue');

        $response = match ($operation) {
            'store' => $this->post('/records/data/project_issue', ['values' => ['title' => '新しい課題', 'project' => $project->id]]),
            'update' => $this->put("/records/data/project_issue/{$issue->id}", ['values' => ['title' => '変えた課題', 'project' => $project->id], 'version' => $issue->version()]),
            'destroy' => $this->delete("/records/data/project_issue/{$issue->id}"),
        };

        $response->assertStatus(303)->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => self::MESSAGES[$operation]]);

        if ($operation === 'store') {
            $this->assertSame($count + 1, $this->recordCountOf('project_issue'));
        }

        if ($operation === 'store' && $destination === 'show') {
            $location = (string) $response->headers->get('Location');
            if (preg_match('#/records/data/project_issue/(\d+)$#D', $location, $matches) !== 1) {
                $this->fail("移り先が詳細の形でない: {$location}");
            }
            $created = $this->recordOf('project_issue', (int) $matches[1]);
            $this->assertSame('新しい課題', $created->field_values[$this->fieldIdOf('project_issue', 'title')] ?? null);
            $url = $location;
        } else {
            $url = match ($destination) {
                'show' => "/records/data/project_issue/{$issue->id}",
                'index' => '/records/data/project_issue',
                'home' => '/',
            };
            $response->assertRedirect($url);
        }

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }

    /**
     * 操作(登録・更新・削除)× 許可の組(その操作だけ / その操作と read / 4 操作)と、移り先・その画面のページ。
     *
     * @return array<string, array{'store'|'update'|'destroy', list<RecordModuleAction>, 'show'|'index'|'home', string}>
     */
    public static function operationsAndGrants(): array
    {
        $all = RecordModuleAction::cases();
        $read = RecordModuleAction::Read;

        return [
            'store with create only' => ['store', [RecordModuleAction::Create], 'home', 'Menu/Home'],
            'store with create and read' => ['store', [RecordModuleAction::Create, $read], 'show', 'Records/Show'],
            'store with all actions' => ['store', $all, 'show', 'Records/Show'],
            'update with update only' => ['update', [RecordModuleAction::Update], 'home', 'Menu/Home'],
            'update with update and read' => ['update', [RecordModuleAction::Update, $read], 'show', 'Records/Show'],
            'update with all actions' => ['update', $all, 'show', 'Records/Show'],
            'destroy with delete only' => ['destroy', [RecordModuleAction::Delete], 'home', 'Menu/Home'],
            'destroy with delete and read' => ['destroy', [RecordModuleAction::Delete, $read], 'index', 'Records/Index'],
            'destroy with all actions' => ['destroy', $all, 'index', 'Records/Index'],
        ];
    }

    /**
     * 課題(project_issue)の許可を granted の操作だけにする(ほかの操作の初期ポリシーを消す)。
     *
     * @param  list<RecordModuleAction>  $granted
     */
    private function grantOnly(array $granted): void
    {
        foreach (RecordModuleAction::cases() as $action) {
            if (! in_array($action, $granted, true)) {
                $this->deny('project_issue', $action);
            }
        }
    }

    /**
     * 業務モジュールのレコードの件数。
     */
    private function recordCountOf(string $moduleCode): int
    {
        return Record::query()->where('record_module_id', $this->moduleOf($moduleCode)->id)->count();
    }

    /**
     * 業務モジュールの id のレコード(無ければ失敗)。
     */
    private function recordOf(string $moduleCode, int $id): Record
    {
        return Record::query()->where('record_module_id', $this->moduleOf($moduleCode)->id)->findOrFail($id);
    }

    /**
     * 業務モジュールの項目のコードの id。
     */
    private function fieldIdOf(string $moduleCode, string $fieldCode): int
    {
        return $this->moduleOf($moduleCode)->recordFields()->where('code', $fieldCode)->sole()->id;
    }

    /**
     * コードの業務モジュール。
     */
    private function moduleOf(string $moduleCode): RecordModule
    {
        return RecordModule::query()->where('code', $moduleCode)->sole();
    }
}
