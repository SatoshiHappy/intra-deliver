<?php

namespace Tests\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\PostRecordComment;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzResource;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTermTranslation;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordModule;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * コメントのテストの手助け(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md): 業務処理で書く、名前つきのユーザー、
 * 「ある組織がカレント組織の人だけが読める」業務モジュール(兼務の組織でだけ閲覧できる人の確かめ)。基準日は {@see BuildsRecordModules} の 2026-10-11。
 */
trait BuildsRecordComments
{
    use BuildsRowAccessRecords;

    /**
     * 業務処理でコメントを書く(ユーザー null = ゲスト)。
     *
     * @param  list<int>  $mentions
     */
    protected function postComment(
        ?int $userId,
        int $recordId,
        string $body = '本文',
        array $mentions = [],
        string $module = 'meetings',
    ): int {
        $context = $this->contextFor($userId);

        return app(PostRecordComment::class)->execute($context, $module, $recordId, $body, $mentions);
    }

    /**
     * テストが書いたコメントの数(初期データの見本のコメント = 「プロジェクト」の分を除く)。
     */
    protected function commentsAdded(): int
    {
        $seeded = RecordModule::query()->select('id')->where('code', 'project');

        return RecordComment::query()->whereNotIn('record_module_id', $seeded)->count();
    }

    /**
     * テストが送ったコメントの通知(初期データの見本のコメントの通知 = 「プロジェクト」のレコードを指すものを除く)。
     *
     * @return Builder<Notice>
     */
    protected function commentNotices(): Builder
    {
        $seeded = Record::query()->select('id')
            ->whereIn('record_module_id', RecordModule::query()->select('id')->where('code', 'project'));

        return Notice::query()->where('type', 'records.comment')->whereNotIn('target_id', $seeded);
    }

    /**
     * テストが送ったコメントの通知の数。
     */
    protected function commentNoticesAdded(): int
    {
        return $this->commentNotices()->count();
    }

    /**
     * 403 になる処理を実行し、その文言を返す(ならなければテストを失敗させる)。
     *
     * @param  Closure(): mixed  $action
     */
    protected function forbiddenMessage(Closure $action): string
    {
        try {
            $action();
        } catch (AuthorizationException $exception) {
            return $exception->getMessage();
        }

        $this->fail('403 にならなかった');
    }

    /**
     * 404 になる処理を実行する(ならなければテストを失敗させる。422 になったときは中身を見せて失敗させる)。
     *
     * @param  Closure(): mixed  $action
     */
    protected function assertNotFound(Closure $action): void
    {
        try {
            $action();
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);

            return;
        } catch (ValidationException $exception) {
            $this->fail('422: '.json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->fail('404 にならなかった');
    }

    /**
     * 名前(ja)つきのユーザー(既定は今日に有効)。
     *
     * @param  list<array{0: string, 1: string|null, 2?: bool}>  $terms
     */
    protected function namedUser(string $name, array $terms = self::ALWAYS): User
    {
        $user = $this->user($terms);
        UserTermTranslation::factory()->create([
            'user_term_id' => $user->userTerms()->sole()->id,
            'locale_id' => Locale::query()->where('code', 'ja')->sole()->id,
            'name' => $name,
        ]);

        return $user;
    }

    /**
     * 既定の組織セットの組織。
     */
    protected function defaultSetDepartment(): Department
    {
        return $this->department(DepartmentSet::query()->where('is_default', true)->firstOrFail());
    }

    /**
     * その組織がカレント組織の人だけが read を許される業務モジュール(ほかの人・ゲストは許されない)と、そのレコード 1 件(id を返す)。
     *
     * @return array{RecordModule, int}
     */
    protected function departmentOnlyModule(string $code, Department $department): array
    {
        $module = $this->recordModule($code, []);
        $condition = new SubjectConditionInput(departmentId: $department->id);
        $subjectGroup = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, []);
        $resource = AuthzResource::query()->where('uri', RecordModuleAction::uri($code))->sole();
        app(CreatePolicy::class)->execute(
            $resource->authz_resource_group_id,
            $subjectGroup->id,
            RecordModuleAction::RESOURCE_TYPE,
            RecordModuleAction::Read->value,
            PolicyEffect::Permit,
        );

        return [$module, Record::factory()->create(['record_module_id' => $module->id])->id];
    }
}
