import { expect } from './test';
import type { Page } from '@playwright/test';
import { fillVersionTwoBaseDate } from './seed-dates';
import { submitAndExpectUrl } from './submit';
import { fillPlanFields, submitApplication } from './workflow-operations';

// 既存のデータからの申請の画面の筋(zz-workflow-record-apply-*.spec.ts。docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節)が共有する、
// 申請書の業務モジュール「プロジェクト計画の申請」のレコードの作成・申請の画面の確かめ・DB の用意。

export const MODULE_PATH = '/records/data/project_plan_application';
export const FLOW_CODE = 'project_plan';
export const FLOW_NAME = 'プロジェクト計画の承認';
export const SECOND_FLOW_CODE = 'plan_light';
export const SECOND_FLOW_NAME = 'E2E の簡易承認';
export const SECOND_FLOW_DESCRIPTION = '2 つ目のフローの説明です。';
export const APPLY_FORM = `${FLOW_NAME}の入力`;
export const RECORD_FORM = 'プロジェクト計画の申請の入力';
const HOLD_REASON = '申請済みのため変更できません';

// 切り替えた後の利用者メニューの表示(workflow-operations.ts の switchUser の from)
export const APPLICANT = '佐々木 陽太（東京営業課）';
export const CHIEF = '中村 美咲（東京営業課）';
export const MANAGER = '高橋 誠（第一営業部）';
export const ACCOUNTING = '山田 恵（経理部）';

/**
 * 2 つ目のフロー(申請書の業務モジュール「プロジェクト計画の申請」を指す同じコンテンツ。申請「一般利用者」→ 承認「課長」の 1 本道。
 * 版 1 は過去の日から)を足す tinker の文(作る口 = CreateWorkflowFlow と、そのリソース = WorkflowFlowResources。E2E から DB に書く前例 =
 * zz-workflow-operations.spec.ts の tinker)。
 */
export const CREATE_SECOND_FLOW = [
    '$role = App\\Models\\Authz\\Role::query()->where("code", "general_user")->sole();',
    '$chief = App\\Models\\Master\\User::query()->where("code", "u0002")->sole();',
    '$apply = new App\\Actions\\Workflow\\WorkflowRouteNodeInput("apply", "申請",',
    'App\\Enums\\Workflow\\WorkflowNodeKind::Apply,',
    '[new App\\Actions\\Authz\\Api\\SelectionCondition(',
    'App\\Enums\\Authz\\SelectionConditionKind::Role, $role->id)], ["ja" => "申請"]);',
    '$approve = new App\\Actions\\Workflow\\WorkflowRouteNodeInput("approval1", "承認",',
    'App\\Enums\\Workflow\\WorkflowNodeKind::Approve,',
    '[new App\\Actions\\Authz\\Api\\SelectionCondition(',
    'App\\Enums\\Authz\\SelectionConditionKind::User, $chief->id)], ["ja" => "承認"]);',
    '$content = App\\Models\\Workflow\\WorkflowContent::query()',
    '->whereRelation("recordModule", "code", "project_plan_application")->sole();',
    `app(App\\Actions\\Workflow\\CreateWorkflowFlow::class)->execute("${SECOND_FLOW_CODE}",`,
    `"${SECOND_FLOW_NAME}", "${SECOND_FLOW_DESCRIPTION}", $content->code, [$apply, $approve],`,
    'Carbon\\CarbonImmutable::parse("2025-04-01"), null, [], 15);',
    `app(Database\\Seeders\\WorkflowFlowResources::class)->createFor("${SECOND_FLOW_CODE}");`,
].join('');

/**
 * 申請書の業務モジュールの update の許可をすべて禁止にする tinker の文
 * (read と create だけの人にする。認可設定の画面のセルを禁止にするのと同じ業務処理)。
 */
export const DENY_UPDATE = [
    '$group = App\\Models\\Authz\\AuthzResourceGroup::query()->where("code",',
    'App\\Enums\\Records\\RecordModuleAction::resourceGroupCode("project_plan_application"))->sole();',
    '$policies = App\\Models\\Authz\\AuthzPolicy::query()->where("authz_resource_group_id", $group->id)',
    '->where("resource_type", App\\Enums\\Records\\RecordModuleAction::RESOURCE_TYPE)',
    '->where("action", "update")->get();',
    'foreach ($policies as $policy) {',
    'app(App\\Actions\\Authz\\UpdatePolicyEffect::class)',
    '->execute($policy->id, App\\Enums\\Authz\\PolicyEffect::Deny);',
    '}',
].join('');

/**
 * 業務データの画面で申請書のレコードを 1 件登録して詳細へ移り、詳細の URL を返す。申請書の業務モジュールは参照制限の既定が「する」
 * (設計/ワークフロー/初期データ.md の 3 節)。作成者でない人にも業務データの画面で見せる筋は unrestricted でチェックを外して登録する。
 */
export async function createRecord(
    page: Page,
    name: string,
    unrestricted = false,
): Promise<string> {
    await page.goto(`${MODULE_PATH}/create`);
    const form = page.getByRole('form', { name: RECORD_FORM });
    await fillPlanFields(page, form, name);
    const restricted = form.getByRole('checkbox', {
        name: '参照を制限する',
        exact: true,
    });
    await expect(restricted).toBeChecked();
    if (unrestricted) {
        await restricted.uncheck();
    }
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_plan_application\/\d+$/,
    );
    await expect(
        page.getByRole('heading', { level: 1, name, exact: true }),
    ).toBeVisible();

    return page.url();
}

/**
 * URL の末尾のレコードの id。
 */
export function recordIdOf(url: string): string {
    return new URL(url).pathname.split('/').at(-1) ?? '';
}

/**
 * 詳細の見出しのボタンが、持ち主(申請済み)のときの形: 理由の 1 行が出て、編集・申請する・削除が押せない形、複製は押せる(元を変えない)。
 */
export async function expectHeld(page: Page) {
    const main = page.getByRole('main');
    await expect(main.getByText(HOLD_REASON, { exact: true })).toBeVisible();

    for (const name of ['編集', '申請する', '削除']) {
        await expect(
            main.getByRole('button', { name, exact: true }),
        ).toBeDisabled();
    }
    await expect(
        main.getByRole('link', { name: '複製', exact: true }),
    ).toBeVisible();
}

/**
 * 案件の詳細の「承認」を押し、確かめのダイアログの「決定」で承認する。
 */
export async function approve(page: Page) {
    await page
        .getByRole('main')
        .getByRole('button', { name: '承認', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: '承認する' })
            .getByRole('button', { name: '決定', exact: true }),
    );
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();
}

/**
 * 申請の画面(既存のデータ)の見出しの 1 行が出ていることを確かめる。
 */
export async function expectRecordHeadingLine(page: Page, name: string) {
    await expect(
        page.getByText(`${name}(既存のデータ)から申請します。`),
    ).toBeVisible();
}

/**
 * 申請の画面(既存のデータ。フロー project_plan)を開いて入力の欄を返す。値の入った欄と見出しの 1 行も確かめる。
 */
export async function openApplyScreen(
    page: Page,
    recordUrl: string,
    name: string,
) {
    await page.goto(
        `/workflow/flows/${FLOW_CODE}/apply?record=${recordIdOf(recordUrl)}`,
    );
    const form = page.getByRole('form', { name: APPLY_FORM });
    await expect(
        form.getByRole('textbox', { name: 'プロジェクト名', exact: true }),
    ).toHaveValue(name);
    await expectRecordHeadingLine(page, name);

    return form;
}

/**
 * 申請の画面の値を直し、申請基準日を版 2 の日にして(部分の読み直しの後もクエリ record が残り、既存のデータの画面のまま)、申請して案件の詳細へ移る。
 */
export async function editAndApply(
    page: Page,
    recordUrl: string,
    name: string,
) {
    const form = page.getByRole('form', { name: APPLY_FORM });
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill('150');
    await fillVersionTwoBaseDate(form);
    await expect(page).toHaveURL(
        (url) => url.searchParams.get('record') === recordIdOf(recordUrl),
    );
    await expectRecordHeadingLine(page, name);
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
}

/**
 * フローを選ぶダイアログ(入口が 2 つ = 初期データの計画のフローと 2 つ目のフロー)の題・本文・行(名前と説明)を確かめて、ダイアログを返す。
 */
export async function expectFlowDialog(page: Page, name: string) {
    const dialog = page.getByRole('dialog', { name: '申請するフローを選択' });
    await expect(dialog).toContainText(
        `${name}を申請できるフローが2つあります。`,
    );
    const rows = dialog.getByRole('radio');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toHaveAccessibleName(new RegExp(`^${FLOW_NAME}`));
    await expect(rows.nth(1)).toHaveAccessibleName(
        new RegExp(`^${SECOND_FLOW_NAME}`),
    );
    await expect(dialog).toContainText(SECOND_FLOW_DESCRIPTION);

    return dialog;
}

/**
 * 別のタブで業務データの画面の編集を開き、予算を直して更新する(版はレコードの更新時刻の秒なので、作った秒と別の秒に更新する)。
 */
export async function updateBudgetInAnotherTab(page: Page, recordUrl: string) {
    await page.waitForTimeout(1100);
    const other = await page.context().newPage();
    await other.goto(`${recordUrl}/edit`);
    const form = other.getByRole('form', { name: RECORD_FORM });
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill('999');
    await submitAndExpectUrl(
        other,
        form.getByRole('button', { name: '更新', exact: true }),
        recordUrl,
    );
    await other.close();
}
