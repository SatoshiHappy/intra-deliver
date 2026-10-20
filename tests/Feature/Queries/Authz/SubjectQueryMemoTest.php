<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\SubjectQuery;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 判定を求める者の d の事実の使い回し(docs の work の 2026101102_段0の残り 修正方針 R1): 同じ SubjectQuery のインスタンスで、同じ判定を求める者
 * (ユーザー ID・選んだ組織 ID・接続元の IPv4 アドレスの値)と基準日には 1 回だけ組み立て、どれか 1 つでも違えば組み立て直す。
 */
final class SubjectQueryMemoTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $user;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $set = DepartmentSet::factory()->create(['is_default' => true]);
        $this->department = $this->department($set);
        $this->user = $this->user();
        $this->join($this->user, $this->department, isMain: true);
    }

    public function test_builds_the_subject_once_for_the_same_requester_and_date(): void
    {
        $subjects = app(SubjectQuery::class);
        $first = $subjects->subjectOn($this->requester(), self::day(self::DATE));

        $queries = $this->queriesDuring(fn () => $subjects->subjectOn($this->requester(), self::day(self::DATE)));

        $this->assertSame(0, $queries);
        $this->assertSame([$this->department->id], $first->departmentIds);
    }

    #[TestWith(['user'])]
    #[TestWith(['department'])]
    #[TestWith(['ipv4'])]
    #[TestWith(['date'])]
    public function test_builds_the_subject_again_when_one_value_differs(string $differs): void
    {
        $subjects = app(SubjectQuery::class);
        $subjects->subjectOn($this->requester(), self::day(self::DATE));
        $requester = match ($differs) {
            'user' => new Requester($this->user()->id, '192.168.0.1'),
            'department' => new Requester($this->user->id, '192.168.0.1', $this->department->id),
            'ipv4' => new Requester($this->user->id, '192.168.0.2'),
            default => $this->requester(),
        };
        $date = $differs === 'date' ? '2024-04-02' : self::DATE;

        $queries = $this->queriesDuring(fn () => $subjects->subjectOn($requester, self::day($date)));

        $this->assertGreaterThan(0, $queries);
        $this->assertSame($requester->ipv4Address, $subjects->subjectOn($requester, self::day($date))->ipv4Address);
    }

    private function requester(): Requester
    {
        return new Requester($this->user->id, '192.168.0.1');
    }

    /**
     * 処理の間に DB へ送った問い合わせの数。
     */
    private function queriesDuring(Closure $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
