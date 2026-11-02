import { expect, test } from '../support/test';
import {
    ADMINISTRATOR,
    openOverviewAs,
    press,
    resourceRow,
    startEditing,
    subjectCell,
    toasts,
} from './authz-editor-support';

// 認可設定の編集の 422(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.3 の 6・4 節、設計/ワークフロー/申請の周辺/画面.md 6 節):
// 守りの 422(トーストを出し、セルは元のまま)と、同時の編集の 422(トーストに「再読み込み」)。システム管理者に切り替えて確かめる。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const GUARD =
    'この変更を行うと認可設定を使用できなくなるため、変更できません。';
const STALE = 'ほかの利用者が変更しました。画面を再読み込みしてください。';

/** 束「認可設定」のシステム管理者の列の直接の設定を、業務処理で消す tinker の文 */
const DELETE_ADMINISTRATOR_PERMIT_ON_AUTHZ = [
    '$group = App\\Models\\Authz\\AuthzResourceGroup::query()',
    '->where("code", "http-services.authz.settings")->sole();',
    '$subject = App\\Models\\Authz\\AuthzSubjectGroup::query()',
    '->where("purpose", App\\Enums\\Authz\\SubjectGroupPurpose::Policy)',
    '->has("authzSubjectConditions", "=", 1)',
    '->whereRelation("authzSubjectConditions.role", "code", "system_admin")->sole();',
    '$policy = App\\Models\\Authz\\AuthzPolicy::query()',
    '->where(["authz_resource_group_id" => $group->id, "authz_subject_group_id" => $subject->id])->sole();',
    'app(App\\Actions\\Authz\\DeletePolicy::class)->execute($policy->id);',
].join('');

// ルートの束「認可設定」(開く・保存の口を含む)を、システム管理者の列で禁止にしようとすると守りに外れる。継承のセルを 1 回押すと直接の許可、もう 1 回で禁止の要求。
// 禁止へは進めないので、直接の許可(効く設定は継承のときと同じ)が残る。画面からは未設定に戻せないので、筋の終わりに業務処理で消す
// (同じ組のほかの spec が束「認可設定」のシステム管理者の継承を前提にできるように)。
test('守りに外れる変更はトーストが出て、セルは元のまま', async ({
    page,
    artisan,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await startEditing(page);

    const row = resourceRow(page, '認可設定');
    const cell = await subjectCell(page, row, 'システム管理者');
    await expect(cell).toContainText('許可(画面・処理から継承)');
    await press(page, cell);
    await expect(cell).toHaveText('許可');

    await press(page, cell);
    await expect(page.getByText(GUARD)).toBeVisible();
    await expect(cell).toHaveText('許可');
    await expect(page.getByRole('button', { name: '再読み込み' })).toHaveCount(
        0,
    );

    artisan(['tinker', '--execute', DELETE_ADMINISTRATOR_PERMIT_ON_AUTHZ]);
    await page.reload();
    await expect(cell).toContainText('許可(画面・処理から継承)');
});

test('2 つの画面で同じセルを変えると、後の画面はトーストに「再読み込み」が付き、押すと最新になる', async ({
    page,
    context,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await startEditing(page);
    const other = await context.newPage();
    await other.goto('/authz/overview');
    await startEditing(other);

    const cell = await subjectCell(
        page,
        resourceRow(page, '共通マスタ'),
        'システム管理者',
    );
    const stale = await subjectCell(
        other,
        resourceRow(other, '共通マスタ'),
        'システム管理者',
    );
    await press(page, cell);
    await expect(cell).toHaveText('許可');

    // 別の画面は継承のまま見ているので、未設定からの変更が食い違う
    await press(other, stale);
    await expect(other.getByText(STALE)).toBeVisible();
    // 422 の応答の後の部分再読み込みで、セルはもう最新(直接の許可)になっている
    await expect(stale).toHaveText('許可');
    await other.getByRole('button', { name: '再読み込み' }).click();
    await expect(stale).toHaveText('許可');
    await expect(toasts(other)).toHaveCount(0);

    // 元の設定へ戻す(許可 → 禁止 → 未設定)
    await press(other, stale);
    await press(other, stale);
    await expect(stale).toContainText('許可(画面・処理から継承)');
});
