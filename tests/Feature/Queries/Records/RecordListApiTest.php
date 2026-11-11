<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordListQuery;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールのコードで受ける一覧の読み取り(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の
 * RecordListQuery の行。判定の順 = 設計/業務データの入れ物/認可とメニュー/README.md 2.2、行の判定 = 同 行の権限.md 2.4 の業務の一覧)。
 *
 * デシジョン表(結果の優先順 = 404 → 403): L1 = 業務モジュールが無い・明細(read はどれでも)→ 404、L2 = 親の無い業務モジュールで
 * read が無い → 403(文 = NOT_ALLOWED_MESSAGE)、L3 = read がある → 行の判定を通るレコードだけで数えて並べる。
 * 返す形・条件・並び・ページは RecordListApiReadingTest。
 */
final class RecordListApiTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    /**
     * L1: 業務モジュール(無いコード・read のある親の明細・read の無い親の明細)と判定を求める者(利用者・ゲスト)。
     *
     * @return array<string, array{string, bool}>
     */
    public static function missingModules(): array
    {
        return [
            '無いコード・利用者' => ['missing', true],
            '無いコード・ゲスト' => ['missing', false],
            'read のある親の明細のモジュール・利用者' => ['readable', true],
            'read のある親の明細のモジュール・ゲスト' => ['readable', false],
            'read の無い親の明細のモジュール・利用者(結果の優先 = 404)' => ['denied', true],
            'read の無い親の明細のモジュール・ゲスト(結果の優先 = 404)' => ['denied', false],
        ];
    }

    /**
     * L2: read の無い判定を求める者(ロールで read を外した利用者・ゲスト)。
     *
     * @return array<string, array{bool}>
     */
    public static function deniedRequesters(): array
    {
        return ['ロールで read を外した利用者' => [true], 'ゲスト' => [false]];
    }

    #[DataProvider('missingModules')]
    public function test_l1_a_missing_or_details_module_is_not_found(string $module, bool $user): void
    {
        $readable = $this->detailsField($this->recordModule('readable'), 'tasks');
        $denied = $this->detailsField($this->recordModule('denied', [RecordModuleAction::Create]), 'items');
        $code = match ($module) {
            'readable' => $this->childOf($readable)->code,
            'denied' => $this->childOf($denied)->code,
            default => 'missing',
        };

        $this->expectException(ModelNotFoundException::class);

        $this->listOf($this->requester($user), $code);
    }

    #[DataProvider('deniedRequesters')]
    public function test_l2_without_read_is_forbidden_with_the_message_of_the_business_screens(bool $user): void
    {
        $this->recordModule('secrets', [RecordModuleAction::Create, RecordModuleAction::Update]);

        try {
            $this->listOf($this->requester($user), 'secrets');
            $this->fail('403 にならない');
        } catch (AuthorizationException $exception) {
            $this->assertSame(RecordFormQuery::NOT_ALLOWED_MESSAGE, $exception->getMessage());
        }
    }

    /**
     * L3: 行の判定を通るレコードだけで数え、どのページにも通らないレコードが出ない。行の権限.md 2.4 の表の行 = 参照制限の無いレコード(R1)、
     * ゲスト(R3)、作成者(R4。stranger の otherId)、関係者の印の項目(R5。attendee)、参照者(R6。viewer とその組織の viewerMember)、
     * どれでもない人(R7。watcher と、互いのレコードの attendee・stranger)。全件の参照(R2)は下の別のテスト。
     * 表の「どれでも」の欄の展開は、同じ行の判定の 1 か所(RecordRowAccess::constrain)を通る RecordRowAccessControllerTest が持つ
     * (この口は画面と同じ RecordListing::listed を通るので結果は変わらない)。
     */
    public function test_l3_counts_and_pages_only_the_records_passing_the_row_access(): void
    {
        $fixture = $this->rowAccessFixture();
        $open = array_map(fn () => Record::factory()->create([
            'record_module_id' => $fixture['module']->id, 'row_access_restricted' => false,
        ])->id, range(1, 2));
        $related = [...array_reverse($open), $fixture['recordId']];
        $expected = [
            'attendee' => $related,
            'stranger' => [...array_reverse($open), $fixture['otherId']],
            'viewer' => $related,
            'viewerMember' => $related,
            'watcher' => array_reverse($open),
            'guest' => array_reverse($open),
        ];

        foreach ($expected as $who => $ids) {
            $requester = new Requester($who === 'guest' ? null : $fixture['people'][$who]->id, null);
            $first = $this->listOf($requester, 'meetings', perPage: 1)['records'];
            $paged = [];

            foreach (range(1, count($ids) + 1) as $page) {
                $listed = $this->listOf($requester, 'meetings', page: $page, perPage: 1);
                $paged = [...$paged, ...array_column($listed['records']['data'], 'id')];
            }

            $this->assertSame([count($ids), count($ids)], [$first['total'], $first['lastPage']], $who);
            $this->assertSame([...$ids, $ids[count($ids) - 1]], $paged, $who);
        }
    }

    /**
     * R2: 全件の参照を許したロール(初期データのシステム管理者 u0004)の利用者は、関係者でも参照者でもない参照制限のあるレコードも数える。
     * 全件の参照はセット record-modules の先頭の許可なので、初期データの業務モジュール project で確かめる。対照 = 全件の参照の無い
     * 認証済みの利用者には、同じレコード(別の利用者が作り、関係者も参照者もいない)が出ない(初期データが変わっても R2 だけが理由になる)。
     */
    public function test_r2_reading_all_by_the_role_counts_every_restricted_record(): void
    {
        RowAccessRestriction::restrictCode('project');
        $moduleId = RecordModule::query()->where('code', 'project')->sole()->id;
        $administrator = User::query()->where('code', 'u0004')->sole();
        $unrelated = Record::factory()->create([
            'record_module_id' => $moduleId,
            'created_by_user_id' => $this->user()->id,
            'row_access_restricted' => true,
        ])->id;
        $all = Record::query()->where('record_module_id', $moduleId)->whereNull('parent_record_id')->count();

        $listed = $this->listOf(new Requester($administrator->id, null), 'project', perPage: 100)['records'];
        $plain = $this->listOf($this->requester(true), 'project', perPage: 100)['records'];

        $this->assertSame([$all, $all], [$listed['total'], count($listed['data'])]);
        $this->assertContains($unrelated, array_column($listed['data'], 'id'));
        $this->assertNotContains($unrelated, array_column($plain['data'], 'id'));
        $this->assertLessThan($all, $plain['total']);
    }

    /**
     * 判定はふだんのとおりで、d に有効でない利用者はゲストとして判定する(呼び手が依頼者の有効を先に確かめる理由)。
     */
    public function test_a_user_not_effective_on_the_date_is_judged_as_the_guest(): void
    {
        $module = $this->guestReadableModule('guest_notes');
        $open = Record::factory()->create(['record_module_id' => $module->id, 'row_access_restricted' => false])->id;
        Record::factory()->create(['record_module_id' => $module->id, 'row_access_restricted' => true]);
        $ended = $this->user([['2020-01-01', '2026-01-01']]);

        $listed = $this->listOf(new Requester($ended->id, null), 'guest_notes')['records'];

        $this->assertSame([[$open], 1], [array_column($listed['data'], 'id'), $listed['total']]);
        $this->expectException(AuthorizationException::class);
        $this->listOf(new Requester($this->user()->id, null), 'guest_notes');
    }

    public function test_less_than_one_a_page_is_a_logic_error(): void
    {
        $this->recordModule('projects');

        $this->expectException(LogicException::class);

        $this->listOf($this->requester(false), 'projects', perPage: 0);
    }

    public function test_reads_without_writes_or_locks(): void
    {
        $fixture = $this->rowAccessFixture();
        $requester = new Requester($fixture['people']['attendee']->id, null);

        $statements = $this->statementsOf(
            fn () => $this->listOf($requester, 'meetings', ['title' => '定例'], 'title', 1, 2),
        );

        $this->assertNotSame([], $statements);
        $this->assertReadOnly($statements);
    }

    /**
     * ゲストにだけ read を許す業務モジュール(認証済みの利用者には許さない)。
     */
    private function guestReadableModule(string $code): RecordModule
    {
        $module = RecordModule::factory()->create(['code' => $code, 'name' => $code]);
        $groupCode = RecordModuleAction::resourceGroupCode($code);
        $type = RecordModuleAction::RESOURCE_TYPE;
        $resource = app(CreateResource::class)->execute($groupCode, null, $type, RecordModuleAction::uri($code), []);
        $guests = app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous), []);
        app(CreatePolicy::class)->execute(
            $resource->authz_resource_group_id,
            $guests->id,
            $type,
            RecordModuleAction::Read->value,
            PolicyEffect::Permit,
        );

        return $module;
    }

    /**
     * 判定を求める者(利用者 = 新しく作った有効なユーザー、ゲスト = ユーザーなし)。
     */
    private function requester(bool $user): Requester
    {
        return new Requester($user ? $this->user()->id : null, null);
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     * @param  array<array-key, mixed>|null  $fieldCodes
     * @return array{records: array{data: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>,
     *     currentPage: int, lastPage: int, total: int},
     *     query: array{filters: array<string, mixed>, sort: string, fields: list<string>}}
     */
    private function listOf(
        Requester $requester,
        string $moduleCode,
        array $conditions = [],
        ?string $sort = null,
        int $page = 1,
        int $perPage = 20,
        ?array $fieldCodes = null,
    ): array {
        $date = self::day(self::TODAY);

        return app(RecordListQuery::class)
            ->list($requester, $moduleCode, $conditions, $sort, $page, $perPage, $fieldCodes, $date, 'ja');
    }

    /**
     * @return list<string> 呼んだ後までに流れた SQL(小文字)
     */
    private function statementsOf(Closure $action): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        $action();

        return $statements;
    }

    /**
     * 読み取りの文だけで、行の鍵と勧告ロックを取らない。
     *
     * @param  list<string>  $statements
     */
    private function assertReadOnly(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|with)\b/', $sql);
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('for share', $sql);
            $this->assertStringNotContainsString('pg_advisory', $sql);
        }
    }
}
