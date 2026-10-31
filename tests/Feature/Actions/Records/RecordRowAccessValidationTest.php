<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\RecordRowAccessInput;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\Record;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 参照制限の入力の確かめ(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 3・4、失敗と定義の変更.md 10 節、
 * メニューと初期データ.md 8 節の「書き込み」): 参照者は 99 個・100 個が通り 101 個で 422。同じ相手の 2 度目・d に有効でない新しい相手・無い相手・
 * 真偽でないチェック・配列でない参照者・相手の形でない要素(ほかのキーを持つ要素を含む)は 422。今の参照者に残る退職者は確かめ直さない。
 * 同じキーの誤りの優先順(W3・W8 が先、形の誤りでは W3・W8 を評価しない)と、項目の値の誤りと一緒に返すこと。
 */
final class RecordRowAccessValidationTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use CatchesValidationErrors;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relatedModule('meetings');
        $this->writer = $this->user();
    }

    public function test_99_and_100_viewers_are_accepted_and_101_are_rejected(): void
    {
        $viewers = array_map(fn () => $this->viewer($this->user()), range(1, 101));

        $this->assertSame(99, $this->countViewers($this->create(true, array_slice($viewers, 0, 99))));
        $this->assertSame(100, $this->countViewers($this->create(true, array_slice($viewers, 0, 100))));
        $this->assertSame(
            ['row_access_viewers' => '参照者は 100 件までにしてください'],
            $this->validationErrors(fn () => $this->create(true, $viewers)),
        );
    }

    public function test_a_party_twice_is_rejected_and_a_user_and_a_department_sharing_an_id_differ(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
        $viewer = $this->viewer($user);

        $this->assertSame(
            ['row_access_viewers' => '参照者に同じ値が重複しています'],
            $this->validationErrors(fn () => $this->create(true, [$viewer, $viewer])),
        );
        $both = [$viewer, ['type' => 'department', 'id' => $department->id]];
        $this->assertSame(2, $this->countViewers($this->create(true, $both)));
    }

    public function test_a_new_viewer_not_effective_today_or_missing_is_rejected_at_its_index(): void
    {
        $retired = $this->user([['2020-01-01', '2026-10-01']]);
        $missing = ['type' => 'department', 'id' => 999999999];

        $this->assertSame(
            ['row_access_viewers.1' => '参照者の値が正しくありません', 'row_access_viewers.2' => '参照者の値が正しくありません'],
            $this->validationErrors(
                fn () => $this->create(true, [$this->viewer($this->user()), $this->viewer($retired), $missing]),
            ),
        );
    }

    public function test_a_retired_viewer_kept_from_the_current_value_is_not_checked_again(): void
    {
        $retired = $this->user([['2020-01-01', '2026-10-01']]);
        $id = $this->create(true, []);
        $this->addViewerRows($id, [$retired]);
        $this->rowAccessViewersShouldBe($id, [$retired]);

        $this->update($id, true, [$this->viewer($this->user()), $this->viewer($retired)]);

        $this->assertSame(2, $this->countViewers($id));
        $ended = $this->user([['2020-01-01', '2026-10-01']]);
        $this->assertSame(
            ['row_access_viewers.1' => '参照者の値が正しくありません'],
            $this->validationErrors(
                fn () => $this->update($id, true, [$this->viewer($retired), $this->viewer($ended)]),
            ),
        );
    }

    #[TestWith(['true'])]
    #[TestWith([1])]
    #[TestWith([[true]])]
    public function test_a_check_other_than_a_boolean_is_rejected(mixed $restricted): void
    {
        $this->assertSame(
            ['row_access_restricted' => '参照制限の値が正しくありません'],
            $this->validationErrors(fn () => $this->create($restricted)),
        );
    }

    #[TestWith(['user:1'])]
    #[TestWith([['type' => 'user', 'id' => 1]])]
    public function test_viewers_that_are_not_a_list_are_rejected(mixed $viewers): void
    {
        $this->assertSame(
            ['row_access_viewers' => '参照者の値が正しくありません'],
            $this->validationErrors(fn () => $this->create(true, $viewers)),
        );
    }

    /**
     * 相手の形でない要素(id が整数でない・種類が違う・ほかのキーを持つ・配列でない)は、その添字のキーの形の誤り。
     */
    public function test_an_element_that_is_not_a_party_is_rejected_at_its_index(): void
    {
        $user = $this->user();
        $viewers = [
            ['type' => 'user', 'id' => (string) $user->id],
            ['type' => 'record', 'id' => 1],
            ['type' => 'user', 'id' => $user->id, 'name' => '名前'],
            $user->id,
            $this->viewer($user),
        ];
        $message = '参照者の値が正しくありません';

        $this->assertSame(
            [
                'row_access_viewers.0' => $message,
                'row_access_viewers.1' => $message,
                'row_access_viewers.2' => $message,
                'row_access_viewers.3' => $message,
            ],
            $this->validationErrors(fn () => $this->create(true, $viewers)),
        );
    }

    /**
     * 同じキーの誤りの優先順(9.2 の 4): W3・W8 は 101 個の誤りより先。要素の形の誤りでは W3・W8 を評価せず、項目の値の誤りは一緒に返る。
     */
    public function test_the_order_of_the_errors_of_the_same_key(): void
    {
        $viewers = array_map(fn () => $this->viewer($this->user()), range(1, 101));
        $open = $this->create(false);

        $this->assertSame(
            ['row_access_viewers' => RecordRowAccessInput::NOT_RESTRICTED],
            $this->validationErrors(fn () => $this->update($open, null, $viewers)),
        );
        $this->assertSame(
            ['row_access_viewers' => RecordRowAccessInput::NOT_RESTRICTED],
            $this->validationErrors(fn () => $this->create(false, $viewers)),
        );
        $this->assertSame(
            ['values.attendee' => 'attendeeの値が正しくありません', 'row_access_viewers.0' => '参照者の値が正しくありません'],
            $this->validationErrors(fn () => $this->create(false, [['type' => 'user']], ['attendee' => 'x'])),
        );
    }

    public function test_the_shape_of_the_check_does_not_stop_the_other_viewer_checks(): void
    {
        $viewer = $this->viewer($this->user());

        $this->assertSame(
            ['row_access_restricted' => '参照制限の値が正しくありません', 'row_access_viewers' => '参照者に同じ値が重複しています'],
            $this->validationErrors(fn () => $this->create('yes', [$viewer, $viewer])),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(mixed $restricted = null, mixed $viewers = null, array $values = []): int
    {
        $input = new RecordInput(
            ['title' => '定例', ...$values],
            rowAccessRestricted: $restricted,
            rowAccessViewers: $viewers,
        );

        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $input);
    }

    private function update(int $id, mixed $restricted = null, mixed $viewers = null): void
    {
        $input = new RecordInput(['title' => '定例'], rowAccessRestricted: $restricted, rowAccessViewers: $viewers);
        app(UpdateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $id, $input, null);
    }

    /**
     * @return array{type: string, id: int}
     */
    private function viewer(User $user): array
    {
        return ['type' => 'user', 'id' => $user->id];
    }

    private function countViewers(int $id): int
    {
        return Record::query()->findOrFail($id)->rowAccessViewers()->count();
    }

    /**
     * @param  list<User>  $users
     */
    private function rowAccessViewersShouldBe(int $id, array $users): void
    {
        $this->assertSame(
            array_map(fn (User $user) => $user->id, $users),
            Record::query()->findOrFail($id)->rowAccessViewers()->pluck('user_id')->all(),
        );
    }
}
