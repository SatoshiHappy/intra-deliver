<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordAudience;
use App\Queries\Records\Api\RecordViewAccess;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 関係者の印を付けた型「ユーザーと組織」の項目(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の「関係者の印」・「通知」、ADR 0028 の決定 5):
 * 値のユーザーと、値の組織に直接所属する人(下の組織は含めない)が関係者として、見る人を絞った業務モジュールのレコードを見られ、関係者の通知の宛先に入る。
 * 行の判定と宛先の口は型を見ないので、型「ユーザー」「組織」の項目と同じ結果になる。業務モジュールの read の有無を展開する(read が上限)。
 *
 * 人(キー): partyUser = 値のユーザー、sectionMember = 値の組織に所属、childMember = 値の組織の下の組織にだけ所属、sameId = 値の組織と同じ id のユーザー
 * (値の組織 sameIdDepartment には所属しない)、stranger = どれにも入らない。レコードの作成者は creator。
 */
final class UserOrDepartmentRowAccessTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /** 関係者(ほかの人 childMember・sameId・stranger は関係しない) */
    private const RELATED = ['creator', 'partyUser', 'sectionMember'];

    /**
     * @param  bool  $isMultiple  項目が複数を選べるか(1 つの値の項目は、相手 1 つずつの 2 項目に分けて入れる)
     */
    #[TestWith([true, true])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([false, false])]
    public function test_the_related_people_see_the_record_only_with_read_and_receive_the_notices(
        bool $canRead,
        bool $isMultiple,
    ): void {
        $this->setUpTheModule($canRead, $isMultiple);
        [$people, $recordId] = $this->recordWithParties($isMultiple);

        foreach ($people as $key => $person) {
            $expected = $canRead && in_array($key, self::RELATED, true);
            $this->assertSame($expected, $this->canView($person, $recordId), $key);
        }

        $related = $canRead ? $this->sorted(array_map(fn (string $key) => $people[$key]->id, self::RELATED)) : [];
        $this->assertSame($related, $this->viewers($recordId));
    }

    public function test_without_the_flag_the_people_in_the_value_are_not_related(): void
    {
        $this->setUpTheModule(true, true, flagged: false);
        [$people, $recordId] = $this->recordWithParties(true);

        foreach (self::RELATED as $key) {
            $this->assertSame($key === 'creator', $this->canView($people[$key], $recordId), $key);
        }
        $this->assertSame([$people['creator']->id], $this->viewers($recordId));
    }

    /**
     * 業務モジュール meetings(レコードは参照制限あり)と、型「ユーザーと組織」の項目。read を許すかで、ゲストと認証済みの全員に許すアクションを変える。
     */
    private function setUpTheModule(bool $canRead, bool $isMultiple, bool $flagged = true): void
    {
        $permitted = $canRead ? null : [RecordModuleAction::Create];
        $module = $this->recordModule('meetings', $permitted);
        RowAccessRestriction::restrict([$module->id]);
        $this->field($module, 'title', RecordFieldType::String, ['is_title' => true]);
        $attributes = ['is_multiple' => $isMultiple, 'grants_row_access' => $flagged];

        foreach ($isMultiple ? ['parties'] : ['party_user', 'party_department'] as $code) {
            $this->field($module, $code, RecordFieldType::UserOrDepartment, $attributes);
        }
    }

    /**
     * 人と、creator が作り、partyUser と組織 section を値に持つレコード。
     *
     * @return array{array<string, User>, int}
     */
    private function recordWithParties(bool $isMultiple): array
    {
        $set = DepartmentSet::factory()->create();
        [$sameId, $sameIdDepartment] = $this->userAndDepartmentWithTheSameId($set);
        $section = $this->department($set);
        $child = $this->department($set);
        $this->include($section, $child);
        $people = ['sameId' => $sameId];

        foreach (['creator', 'partyUser', 'sectionMember', 'childMember', 'stranger'] as $key) {
            $people[$key] = $this->user();
        }

        $this->join($people['sectionMember'], $section, isMain: true);
        $this->join($people['childMember'], $child, isMain: true);
        $user = ['type' => 'user', 'id' => $people['partyUser']->id];
        $department = ['type' => 'department', 'id' => $section->id];
        $other = ['type' => 'department', 'id' => $sameIdDepartment->id];
        $values = $isMultiple
            ? ['parties' => [$user, $department, $other]]
            : ['party_user' => $user, 'party_department' => $department];
        $input = new RecordInput(['title' => '定例', ...$values]);

        $context = $this->contextFor($people['creator']->id);

        return [$people, app(CreateRecord::class)->execute($context, 'meetings', $input)];
    }

    private function canView(User $user, int $recordId): bool
    {
        $requester = new Requester($user->id, null);
        $date = CarbonImmutable::parse(self::TODAY);

        return app(RecordViewAccess::class)->canView($requester, 'meetings', $recordId, $date);
    }

    /**
     * @return list<int>
     */
    private function viewers(int $recordId): array
    {
        return app(RecordAudience::class)->relatedViewers($recordId, CarbonImmutable::parse(self::TODAY));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
