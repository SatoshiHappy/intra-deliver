import { expect, test } from '../support/test';
import type { Locator, Page, Request } from '@playwright/test';
import { UNCATEGORIZED } from './records-admin-support';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';
import {
    openFromAdminMenu,
    openWorkApp,
    sidebarHeading,
    sidebarLink,
    sidebarSection,
    untitledLink,
    untitledSections,
} from '../support/app-navigation';

// 業務データの入れ物の管理の画面(docs の 設計/業務データの入れ物/画面/README.md 6 節の管理の分)。デモユーザー切替でシステム管理者になって
// 確かめる(切替を使うので demo の持ち物 = docs の 設計/デモユーザー切替/デモの取り付け.md 6.1)。画面は PC の幅だけを前提にする。
// 管理の画面は歯車から開き、業務のサイドバー(モジュール分類ごとの区画の見出しと見出しなしの区画)は業務のアプリの画面で引く(表示.md 3.3.7)。
// 筋は前の筋の業務モジュール「備品」を使うので、順に走らせる(業務モジュールは消せないので、E2E の DB は流すたびに作り直す = scripts/ci/front)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/**
 * 業務のアプリの画面を開き、サイドバーの見出しなしの区画のリンクのうち among に当たるものが、expected の並びで出ていることを確かめる。
 */
async function expectUntitledLinks(
    page: Page,
    among: string[],
    expected: string[],
) {
    await openWorkApp(page);
    await expect(
        untitledSections(page)
            .getByRole('link')
            .filter({ hasText: new RegExp(`^(${among.join('|')})$`) }),
    ).toHaveText(expected);
}

/**
 * 管理の項目の追加の画面で、コード・名前・型を入れる。
 */
async function startField(
    page: Page,
    code: string,
    name: string,
    type: string,
) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form.getByRole('textbox', { name: 'コード', exact: true }).fill(code);
    await form.getByRole('textbox', { name: '名前', exact: true }).fill(name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page.getByRole('option', { name: type, exact: true }).click();

    return form;
}

test('歯車の「業務モジュール」で一覧が出て、業務モジュールを作るとサイドバーの「業務」に出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    await openFromAdminMenu(page, '業務モジュール');

    await expect(page).toHaveURL('/records/admin/modules');
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(
        sidebarLink(page, '業務モジュール').locator('svg.lucide-database'),
    ).toHaveCount(1);
    const list = page.getByRole('region', { name: '業務モジュールの一覧' });
    await expect(list.getByRole('row')).toHaveCount(8);
    // 列は名前・コード(と読み上げ用の業務の一覧)だけで、項目の数・レコードの数は出さない
    await expect(list.getByRole('columnheader')).toHaveText([
        '名前',
        'コード',
        '業務の一覧',
    ]);

    await page.getByRole('link', { name: '作成', exact: true }).click();
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill('equipment');
    await form.getByRole('textbox', { name: '名前', exact: true }).fill('備品');
    // アイコンの選択肢は props で受け、既定は「表」。「クリップボード」を選ぶ
    const icon = form.getByRole('combobox', { name: 'アイコン', exact: true });
    await expect(icon).toContainText('表');
    await icon.click();
    await page
        .getByRole('option', { name: 'クリップボード', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '作成', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('業務モジュールを作成しました', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: '備品' }),
    ).toBeVisible();
    await openWorkApp(page);
    await expect(
        untitledLink(page, '備品').locator('svg.lucide-clipboard-list'),
    ).toHaveCount(1);
});

test('項目を足すと、配備なしで業務の一覧とフォームに出て、レコードを登録できる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');

    // 必須の入力の印(コード・名前・型)と、型の一覧の先頭の 2 つ(文字列の 1 行と複数行)= 画面/管理の画面.md 3.4・画面/README.md 5 節
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const blank = page.getByRole('form', { name: '項目の入力' });
    for (const label of ['コード', '名前', '型']) {
        await expect(
            blank.getByRole('group', { name: label, exact: true }),
        ).toContainText(`${label}*`);
    }
    await blank.getByRole('combobox', { name: '型', exact: true }).click();
    await expect(page.getByRole('option').nth(0)).toHaveText('文字列(1 行)');
    await expect(page.getByRole('option').nth(1)).toHaveText('文字列(複数行)');
    await page.keyboard.press('Escape');
    await page.goto('/records/admin/modules/equipment');

    let form = await startField(page, 'item_name', '品名', '文字列(1 行)');
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    await form
        .getByRole('checkbox', { name: '表示の項目', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment');
    await expect(
        page
            .getByRole('region', { name: '備品の一覧' })
            .getByRole('columnheader', { name: '品名' }),
    ).toBeVisible();

    await page.goto('/records/admin/modules/equipment');
    form = await startField(page, 'status', '状態', '選択');
    await form
        .getByRole('textbox', { name: '選択肢のコード(1 行目)', exact: true })
        .fill('in_use');
    await form
        .getByRole('textbox', { name: '選択肢の名称(1 行目)', exact: true })
        .fill('使用中');
    await form
        .getByRole('button', { name: '選択肢を追加', exact: true })
        .click();
    await form
        .getByRole('textbox', { name: '選択肢のコード(2 行目)', exact: true })
        .fill('spare');
    await form
        .getByRole('textbox', { name: '選択肢の名称(2 行目)', exact: true })
        .fill('予備');
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/equipment',
    );

    await page.goto('/records/data/equipment');
    await expect(
        page
            .getByRole('region', { name: '備品の一覧' })
            .getByRole('columnheader', { name: '状態' }),
    ).toBeVisible();
    await page.getByRole('link', { name: '登録', exact: true }).click();
    const recordForm = page.getByRole('form', { name: '備品の入力' });
    await recordForm
        .getByRole('textbox', { name: '品名', exact: true })
        .fill('ノート PC');
    await recordForm
        .getByRole('combobox', { name: '状態', exact: true })
        .click();
    await page.getByRole('option', { name: '使用中', exact: true }).click();
    await submitAndExpectUrl(
        page,
        recordForm.getByRole('button', { name: '登録', exact: true }),
    );

    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: 'ノート PC' }),
    ).toBeVisible();
});

test('規則を足すと登録のフォームに「必須」の印が出て、規則が使う項目は消せず、規則を消すと件数を確かめて消せる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');

    await page.getByRole('button', { name: '規則を追加', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '規則の追加' });
    await dialog
        .getByRole('combobox', { name: '対象の項目', exact: true })
        .click();
    await page.getByRole('option', { name: '品名', exact: true }).click();
    await dialog
        .getByRole('combobox', { name: '条件の項目(1 行目)', exact: true })
        .click();
    await page.getByRole('option', { name: '状態', exact: true }).click();
    await dialog
        .getByRole('combobox', { name: '条件の値(1 行目)', exact: true })
        .click();
    await page.getByRole('option', { name: '予備', exact: true }).click();
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '追加', exact: true }),
    );

    await expect(
        page.getByText('規則を追加しました', { exact: true }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();
    const summary = '状態 が 予備 のとき、品名 は必須';
    await expect(
        page.getByRole('cell', { name: summary, exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: '備品の入力' });
    const itemName = form.getByRole('group', { name: '品名', exact: true });
    await expect(itemName.getByText('必須', { exact: true })).toHaveCount(0);
    await form.getByRole('combobox', { name: '状態', exact: true }).click();
    await page.getByRole('option', { name: '予備', exact: true }).click();
    await expect(itemName.getByText('必須', { exact: true })).toBeVisible();

    await page.goto('/records/admin/modules/equipment');
    await page.getByRole('button', { name: '状態を削除', exact: true }).click();
    const deleteField = page.getByRole('dialog', { name: '項目の削除' });
    await submitAndExpectUrl(
        page,
        deleteField.getByRole('button', { name: '削除', exact: true }),
    );

    await expect(
        page.getByText(
            '状態を使用する規則があります。先に規則を削除してください',
            {
                exact: true,
            },
        ),
    ).toBeVisible();

    await page
        .getByRole('button', { name: `規則「${summary}」を削除`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: '規則の削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('規則を削除しました', { exact: true }),
    ).toBeVisible();

    await page.getByRole('button', { name: '状態を削除', exact: true }).click();
    await expect(deleteField).toContainText(
        '項目「状態」を削除すると、1 件のレコードからこの項目の値が削除されます。',
    );
    await submitAndExpectUrl(
        page,
        deleteField.getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('項目を削除しました', { exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment');
    await expect(
        page
            .getByRole('region', { name: '備品の一覧' })
            .getByRole('columnheader', { name: '状態' }),
    ).toHaveCount(0);
});

test('定義の画面の見出しの切替で別の業務モジュールを選ぶと、その定義の画面へ移る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/project');
    const moduleSwitch = page.getByRole('combobox', {
        name: '業務モジュールの切替',
        exact: true,
    });
    await expect(moduleSwitch).toHaveText('プロジェクト');

    await moduleSwitch.click();
    await page.getByRole('option', { name: '課題', exact: true }).click();

    await expect(page).toHaveURL('/records/admin/modules/project_issue');
    await expect(
        page.getByRole('heading', { level: 1, name: '課題' }),
    ).toBeVisible();
    await expect(moduleSwitch).toHaveText('課題');
});

test('選択肢のコードが __none でも、登録で選んだ値が保存され、検索の条件として送られる', async ({
    page,
}) => {
    // 選択の欄は選択肢のコードをそのまま値にし、画面の内部値(検索欄の真偽の「指定なし」)は選択肢のコードの書式の外にある
    // (docs の 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節)。書式に合う __none は普通の選択肢として扱われる
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    const form = await startField(page, 'kind', '区分', '選択');
    await form
        .getByRole('textbox', { name: '選択肢のコード(1 行目)', exact: true })
        .fill('__none');
    await form
        .getByRole('textbox', { name: '選択肢の名称(1 行目)', exact: true })
        .fill('区分なし');
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    // 追加のときの初期値はオン(検索は全項目でできるのが既定。外すのは管理者)
    await expect(
        form.getByRole('checkbox', { name: '検索条件にする', exact: true }),
    ).toBeChecked();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment/create');
    const recordForm = page.getByRole('form', { name: '備品の入力' });
    await recordForm
        .getByRole('textbox', { name: '品名', exact: true })
        .fill('モニター');
    await recordForm
        .getByRole('combobox', { name: '区分', exact: true })
        .click();
    await page.getByRole('option', { name: '区分なし', exact: true }).click();
    await submitAndExpectUrl(
        page,
        recordForm.getByRole('button', { name: '登録', exact: true }),
    );

    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    await expect(
        page
            .getByRole('region', { name: 'モニターの値' })
            .getByText('区分なし', { exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment');
    const list = page.getByRole('region', { name: '備品の一覧' });
    const row = (name: string) =>
        list.getByRole('row').filter({ hasText: name });
    await expect(row('ノート PC')).toBeVisible();
    await expect(row('モニター')).toBeVisible();

    await page.getByRole('button', { name: '検索条件', exact: true }).click();
    await page.getByRole('combobox', { name: '区分', exact: true }).click();
    await page.getByRole('option', { name: '区分なし', exact: true }).click();
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(page).toHaveURL(/\?(.*&)?filter%5Bkind%5D=__none(&|$)/);
    await expect(row('モニター')).toBeVisible();
    await expect(row('ノート PC')).toHaveCount(0);
});

test('項目のコードは、欄を離れると書式の誤りが出て、直すと消える。予約語は送ったときにサーバーの文言が同じ場所に出る', async ({
    page,
}) => {
    const message =
        'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください';
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    // startField はコードの次に名前を入れるので、コードの欄はここで離れる
    const form = await startField(page, '1abc', '検査用', '文字列(1 行)');
    const codeBlock = form.getByRole('group', { name: 'コード', exact: true });
    const code = form.getByRole('textbox', { name: 'コード', exact: true });
    await expect(codeBlock.getByText(message, { exact: true })).toBeVisible();
    await expect(code).toHaveAttribute('aria-invalid', 'true');
    // 説明は誤りの文言と、欄の下の文字数(業務の画面.md 2.2)
    await expect(code).toHaveAccessibleDescription(`${message} 4 / 50`);

    // 一度出た後は打つたびに評価し直す
    await code.fill('abc');
    await expect(codeBlock.getByText(message)).toHaveCount(0);
    await expect(code).not.toHaveAttribute('aria-invalid', 'true');

    // 前後の空白は除いて見る。50 文字ちょうどは正常
    await code.fill(' abc ');
    await code.blur();
    await expect(codeBlock.getByText(message)).toHaveCount(0);
    await code.fill('a'.repeat(50));
    await code.blur();
    await expect(codeBlock.getByText(message)).toHaveCount(0);

    // 予約語は画面では見ない(書式は合う)。送るとサーバーの文言が同じ場所に出る
    await code.fill('id');
    await code.blur();
    await expect(codeBlock.getByText(message)).toHaveCount(0);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
    );
    await expect(
        codeBlock.getByText('id は予約語のためコードに使用できません', {
            exact: true,
        }),
    ).toBeVisible();

    // 欄を変えるとサーバーの文言は消える
    await code.fill('ids');
    await expect(codeBlock.getByText('予約語')).toHaveCount(0);
});

test('選択肢のコードは、大文字なら欄を離れると選択肢用の誤りが出て、数字で始まる値は誤りにならない', async ({
    page,
}) => {
    const message = 'コードは英小文字・数字・_ の 50 文字までにしてください';
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    const form = await startField(page, 'kind_check', '検査用', '選択');
    const optionCode = form.getByRole('textbox', {
        name: '選択肢のコード(1 行目)',
        exact: true,
    });
    await optionCode.fill('Abc');
    await optionCode.blur();
    await expect(form.getByText(message, { exact: true })).toBeVisible();
    await expect(optionCode).toHaveAttribute('aria-invalid', 'true');
    await expect(optionCode).toHaveAccessibleDescription(`${message} 3 / 50`);

    await optionCode.fill('1a');
    await expect(form.getByText(message)).toHaveCount(0);
    await expect(optionCode).not.toHaveAttribute('aria-invalid', 'true');
});

test('「文字列(複数行)」で作った項目は編集で型が固定の表示になり、項目の表で取っ手を Space → 下矢印 → Space で動かすと、再読み込みしても登録のフォームでも並びが変わる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    const form = await startField(page, 'note', 'メモ', '文字列(複数行)');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'メモを編集', exact: true }).click();
    const editForm = page.getByRole('form', { name: '項目の入力' });
    await expect(editForm).toContainText('型: 文字列(複数行)');
    await expect(
        editForm.getByRole('combobox', { name: '型', exact: true }),
    ).toHaveCount(0);
    await expect(
        editForm.getByRole('checkbox', { name: '複数行', exact: true }),
    ).toHaveCount(0);
    await expect(editForm.getByText('並び順', { exact: true })).toHaveCount(0);

    // 項目の並びは 品名・区分・メモ(前の筋で足した順)。品名を 1 つ下へ
    await page.goto('/records/admin/modules/equipment');
    const fields = page.getByRole('region', { name: '項目', exact: true });
    const rows = fields.getByRole('row');
    await expect(rows.nth(1)).toContainText('品名');
    await expect(rows.nth(2)).toContainText('区分');
    await moveByKeyboard(
        page,
        fields.getByRole('button', { name: '品名の並び順を変更', exact: true }),
        'ArrowDown',
        '品名を区分の位置へ移動しました',
        '/records/admin/modules/equipment/fields/reorder',
    );

    await expect(rows.nth(1)).toContainText('区分');
    await expect(rows.nth(2)).toContainText('品名');
    await expect(rows.nth(3)).toContainText('メモ');

    await page.reload();
    await expect(rows.nth(1)).toContainText('区分');
    await expect(rows.nth(2)).toContainText('品名');

    await page.goto('/records/data/equipment/create');
    const recordForm = page.getByRole('form', { name: '備品の入力' });
    await expect(recordForm.getByRole('group').nth(0)).toHaveAccessibleName(
        '区分',
    );
    await expect(recordForm.getByRole('group').nth(1)).toHaveAccessibleName(
        '品名',
    );
});

// 業務モジュールの名前を変えるので、「備品」の名前を使うほかの筋より後に置く
test('「備品」の定義の画面の「編集」で名前とアイコンを変えると、定義の画面へ移り、サイドバーの「業務」の表示名とアイコンが変わる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    await page.getByRole('link', { name: '編集', exact: true }).click();

    await expect(page).toHaveURL('/records/admin/modules/equipment/edit');
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await expect(form).toContainText('コード: equipment');
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill('備品台帳');
    const icon = form.getByRole('combobox', { name: 'アイコン', exact: true });
    await expect(icon).toContainText('クリップボード');
    await icon.click();
    await page.getByRole('option', { name: '注意', exact: true }).click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('業務モジュールを更新しました', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: '備品台帳' }),
    ).toBeVisible();
    await openWorkApp(page);
    await expect(
        untitledLink(page, '備品台帳').locator('svg.lucide-circle-alert'),
    ).toHaveCount(1);
    await expect(untitledLink(page, '備品')).toHaveCount(0);
});

// 前の筋で「備品台帳」に名前を変えた業務モジュールを使う
test('「サイドバーに表示」を外して更新するとサイドバーから消え、一覧に札「非表示」が出て URL では開け、戻すと「業務」の末尾に出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment/edit');
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    const flag = form.getByRole('checkbox', {
        name: 'サイドバーに表示',
        exact: true,
    });
    await expect(flag).toBeChecked();
    await flag.click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('業務モジュールを更新し、サイドバーで非表示にしました', {
            exact: true,
        }),
    ).toBeVisible();
    await openWorkApp(page);
    await expect(untitledLink(page, '備品台帳')).toHaveCount(0);

    await page.goto('/records/admin/modules');
    const row = page
        .getByRole('region', { name: '業務モジュールの一覧' })
        .getByRole('row')
        .filter({ hasText: 'equipment' });
    await expect(row.getByText('非表示', { exact: true })).toBeVisible();

    await page.goto('/records/data/equipment');
    await expect(
        page.getByRole('heading', { level: 1, name: '備品台帳' }),
    ).toBeVisible();

    await page.goto('/records/admin/modules/equipment/edit');
    await expect(flag).not.toBeChecked();
    await flag.click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('業務モジュールを更新し、サイドバーに表示しました', {
            exact: true,
        }),
    ).toBeVisible();
    await openWorkApp(page);
    await expect(
        untitledSections(page).getByRole('link').last(),
    ).toHaveAccessibleName('備品台帳');
});

// モジュール分類(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 6 節の E2E)。前の筋が作って表示に戻した「備品台帳」を使い、
// 以下の筋も順に走らせる(分類を作る → 業務モジュールを入れる → マウスオーバー → 並べる → 消す)。

/**
 * モジュール分類の画面の木(ツールバーを含む面)。
 */
function categoryTree(page: Page) {
    return page.getByRole('region', { name: 'モジュール分類の木' });
}

/**
 * 木の分類の行(押すと選ぶボタン。名前と件数を持つ)。
 */
function categoryRow(page: Page, name: string) {
    return categoryTree(page)
        .locator('button[aria-pressed]')
        .filter({ hasText: name });
}

/**
 * ツールバーの「作成」の Dialog で分類を作る。
 */
async function createCategory(page: Page, name: string) {
    await categoryTree(page)
        .getByRole('button', { name: '作成', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'モジュール分類の作成' });
    await dialog.getByRole('textbox', { name: '名前', exact: true }).fill(name);
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '作成', exact: true }),
    );
    await expect(
        page.getByText('モジュール分類を作成しました', { exact: true }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();
}

/**
 * 取っ手にフォーカスして Space → 矢印 → Space で 1 つ動かし、並びを送り終わるまで待つ。キーを続けて押すと前のキーの処理より先に届くので、
 * つかんだ(自分の位置の読み上げ)・つかんだ処理のタイマーの後・動いた(読み上げ)を待ってから次を押す。
 */
async function moveByKeyboard(
    page: Page,
    handle: Locator,
    key: 'ArrowUp' | 'ArrowDown',
    announcement: string,
    url: string,
) {
    const saved = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().endsWith(url),
    );
    const name = (await handle.getAttribute('aria-label'))?.replace(
        /の並び順を変更$/,
        '',
    );
    const liveRegion = page.locator('[id^="DndLiveRegion"]');
    await handle.focus();
    await page.keyboard.press('Space');
    await expect(handle).toHaveAttribute('aria-pressed', 'true');
    await expect(liveRegion).toHaveText(`${name}を${name}の位置へ移動しました`);
    // つかんだ読み上げは、矢印の keydown の受け口より先に出る(受け口は、つかんだ処理が並べたタイマーで付く = @dnd-kit/core の
    // KeyboardSensor.attach)。後からタイマーを並べ、それが回るまで待つ
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
    await page.keyboard.press(key);
    await expect(liveRegion).toHaveText(announcement);
    await page.keyboard.press('Space');
    await saved;
}

test('一覧の「モジュール分類」で画面へ移り、「営業」を作ると木に 0 件で出て、サイドバーには出ない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules');
    // 管理のアプリのサイドバーのアイテム「モジュール分類」(22c)と、見出しの右のリンク(ボタンの見た目)は同じアイコン
    await expect(
        sidebarLink(page, 'モジュール分類').locator('svg.lucide-folder-tree'),
    ).toBeVisible();
    const toCategories = page
        .getByRole('main')
        .getByRole('link', { name: 'モジュール分類', exact: true });
    await expect(toCategories.locator('svg.lucide-folder-tree')).toBeVisible();
    await toCategories.click();

    await expect(page).toHaveURL('/records/admin/categories');
    await expect(
        page.getByRole('heading', { level: 1, name: 'モジュール分類' }),
    ).toBeVisible();
    // 編集・削除は分類を選ぶまで押せない
    await expect(
        categoryTree(page).getByRole('button', { name: '編集', exact: true }),
    ).toBeDisabled();
    await expect(
        categoryTree(page).getByRole('button', { name: '削除', exact: true }),
    ).toBeDisabled();

    await createCategory(page, '営業');

    await expect(categoryRow(page, '営業')).toContainText(
        '0 件(サイドバーでは非表示)',
    );
    // 0 件の分類は業務のサイドバーに見出しを出さない
    await openWorkApp(page);
    await expect(sidebarSection(page, '営業')).toHaveCount(0);
});

test('「課題」の編集で「営業」を選ぶと、業務のサイドバーに見出し「営業」が出て、その下に「課題」があり、見出しなしの区画から消える。「課題」を押すと一覧へ移る', async ({
    page,
}) => {
    await openAsAdministrator(
        page,
        '/records/admin/modules/project_issue/edit',
    );
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await form.getByRole('checkbox', { name: '営業', exact: true }).click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        '/records/admin/modules/project_issue',
    );
    await openWorkApp(page);
    // 分類のフォルダは区画の見出し(h3。開閉しない)になり、その下に中身のリンクが並ぶ
    const section = sidebarSection(page, '営業');
    await expect(section.getByRole('link')).toHaveText(['課題']);
    await expect(untitledLink(page, '課題')).toHaveCount(0);

    await section.getByRole('link', { name: '課題', exact: true }).click();

    await expect(page).toHaveURL('/records/data/project_issue');
    await expect(
        page.getByRole('heading', { level: 1, name: '課題' }),
    ).toBeVisible();
    // 課題の一覧でも今のアプリは業務のまま。見出し「営業」の下の「課題」が現在地
    await expect(sidebarHeading(page)).toHaveText('業務');
    await expect(
        section.getByRole('link', { name: '課題', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
});

test('分類を 2 つにして取っ手を Space → 上矢印 → Space で動かすと、再読み込みしても同じ並び', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/categories');
    await createCategory(page, '総務');
    const rows = categoryTree(page)
        .getByRole('list', { name: 'モジュール分類', exact: true })
        .locator(':scope > li');
    await expect(rows.nth(0)).toContainText('営業');
    await expect(rows.nth(1)).toContainText('総務');

    await moveByKeyboard(
        page,
        page.getByRole('button', { name: '総務の並び順を変更', exact: true }),
        'ArrowUp',
        '総務を営業の位置へ移動しました',
        '/records/admin/categories/reorder',
    );

    await expect(rows.nth(0)).toContainText('総務');
    await expect(rows.nth(1)).toContainText('営業');
    await page.reload();
    await expect(rows.nth(0)).toContainText('総務');
    await expect(rows.nth(1)).toContainText('営業');
});

test('「分類なし」の「備品台帳」の取っ手を Space → 上矢印 → Space で動かすと、業務のサイドバーの見出しなしの区画で「会議」の前に出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/categories');
    // 「分類なし」は表示中(プロジェクト・会議・備品台帳)の後ろに、非表示の申請書の業務モジュール 2 つ(ADR 0031。取っ手なし)
    const uncategorized = page
        .getByRole('list', { name: '分類なしの業務モジュール', exact: true })
        .getByRole('listitem');
    await expect(uncategorized.nth(1)).toContainText('会議');
    await expect(uncategorized.nth(2)).toContainText('備品台帳');

    await moveByKeyboard(
        page,
        page.getByRole('button', {
            name: '備品台帳の並び順を変更',
            exact: true,
        }),
        'ArrowUp',
        '備品台帳を会議の位置へ移動しました',
        '/records/admin/modules/reorder',
    );

    await expect(uncategorized.nth(1)).toContainText('備品台帳');
    await expect(uncategorized.nth(2)).toContainText('会議');
    await expectUntitledLinks(page, ['備品台帳', '会議'], ['備品台帳', '会議']);
});

test('「営業」を選んで削除の Dialog で消すと、件数の文が出て、「課題」が「業務」の直下の末尾に戻る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/categories');
    const row = categoryRow(page, '営業');
    await row.click();
    await expect(row).toHaveAttribute('aria-pressed', 'true');

    await categoryTree(page)
        .getByRole('button', { name: '削除', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'モジュール分類を削除しますか',
    });
    await expect(dialog).toContainText('『営業』を削除します。');
    await expect(dialog).toContainText(
        '1 件は「業務」の直下の末尾に移動します。',
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
    );

    await expect(
        page.getByText('モジュール分類を削除しました', { exact: true }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(categoryRow(page, '営業')).toHaveCount(0);
    await openWorkApp(page);
    await expect(sidebarSection(page, '営業')).toHaveCount(0);
    await expect(
        untitledSections(page).getByRole('link').last(),
    ).toHaveAccessibleName('課題');
});

// 以下の 2 筋は削除の筋の後の状態(分類は「総務」だけで 0 件、「分類なし」の表示中は UNCATEGORIZED.afterCategoryDelete)から始め、最後に分類を消して
// すべての業務モジュールを「業務」の直下に戻す(後の records.spec.ts は直下に「プロジェクト」「課題」のリンクがあることを見る)。

/**
 * 木の分類の行の全体(分類の行と中の業務モジュールの行。落とすと所属が変わるときに破線で囲まれる)。
 */
function categoryItem(page: Page, name: string) {
    return categoryTree(page)
        .getByRole('list', { name: 'モジュール分類', exact: true })
        .locator(':scope > li')
        .filter({
            // 内側のロケーターは li の中で探すので、木の region から始まる categoryRow は使えない
            has: page.locator('button[aria-pressed]').filter({ hasText: name }),
        });
}

/**
 * 分類の中の業務モジュールの行(並びの順)。
 */
function modulesIn(page: Page, categoryName: string) {
    return categoryTree(page)
        .getByRole('list', {
            name: `${categoryName}の業務モジュール`,
            exact: true,
        })
        .getByRole('listitem');
}

/**
 * 前の送信が部分再読み込みまで終わるのを待つ(それまで取っ手はドラッグを受けない = dnd-kit の aria-disabled。PUT の応答だけでは終わっていない)。
 * ドラッグの手順の先頭(位置を測る前・Ctrl を押す前)で呼ぶ。
 */
async function waitUntilDraggable(handle: Locator) {
    await expect(handle).toHaveAttribute('aria-disabled', 'false');
}

/**
 * 取っ手をマウスでつかみ、活性の距離(4px)を超えるまで数段に分けて動かしてから、点 to へ動かす(離さない)。
 */
async function grabAndMove(
    page: Page,
    handle: Locator,
    to: { x: number; y: number },
) {
    const from = await handle.boundingBox();

    if (from === null) {
        throw new Error('取っ手が画面に無い');
    }

    const x = from.x + from.width / 2;
    const y = from.y + from.height / 2;

    await page.mouse.move(x, y);
    await page.mouse.down();

    for (const offset of [2, 4, 6, 8]) {
        await page.mouse.move(x, y - offset);
    }

    await page.mouse.move(to.x, to.y, { steps: 10 });
}

/**
 * 取っ手をマウスでつかんで、分類の行(見出し)の上へ動かして落とし、送り終わるまで待つ。落とすと所属が変わる親が囲まれるのを待って up。
 * withControl なら Ctrl を down の前に押し、up の後に離す(ドラッグを始める前から押す経路。途中で押す・離す経路は目視)。
 */
async function dragToCategory(
    page: Page,
    handle: Locator,
    categoryName: string,
    url: string,
    withControl = false,
) {
    await waitUntilDraggable(handle);
    const saved = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().endsWith(url),
    );
    const to = await categoryRow(page, categoryName).boundingBox();

    if (to === null) {
        throw new Error('分類の行が画面に無い');
    }

    if (withControl) {
        await page.keyboard.down('Control');
    }

    await grabAndMove(page, handle, {
        x: to.x + to.width / 2,
        y: to.y + to.height / 2,
    });
    await expect(categoryItem(page, categoryName)).toHaveClass(
        /outline-dashed/,
    );

    if (withControl) {
        await expect(page.getByText('コピー', { exact: true })).toBeVisible();
    }

    await page.mouse.up();

    if (withControl) {
        await page.keyboard.up('Control');
    }

    await saved;
}

/**
 * 取っ手をマウスでつかみ、分類の行(見出し)の上を通り(落とすと所属が変わる親が囲まれるのを待つ)、木の外(ツールバーの「作成」の上 = 候補を
 * 囲む矩形の外)へ出し、囲みが消えてから離す。当たり無しなので何も送られず、読み上げは「<名前>は元の位置のままです」(4.4)。
 */
async function dropOutsideTree(
    page: Page,
    handle: Locator,
    name: string,
    passingCategory: string,
) {
    await waitUntilDraggable(handle);
    const via = await categoryRow(page, passingCategory).boundingBox();
    const outside = await page
        .getByRole('button', { name: '作成', exact: true })
        .boundingBox();

    if (via === null || outside === null) {
        throw new Error('分類の行かツールバーの「作成」が画面に無い');
    }

    await grabAndMove(page, handle, {
        x: via.x + via.width / 2,
        y: via.y + via.height / 2,
    });
    await expect(categoryItem(page, passingCategory)).toHaveClass(
        /outline-dashed/,
    );
    await page.mouse.move(
        outside.x + outside.width / 2,
        outside.y + outside.height / 2,
        { steps: 5 },
    );
    await expect(categoryItem(page, passingCategory)).not.toHaveClass(
        /outline-dashed/,
    );
    await page.mouse.up();
    await expect(page.locator('[id^="DndLiveRegion"]')).toHaveText(
        `${name}は元の位置のままです`,
    );
}

/**
 * 分類を選んで削除の Dialog で消す。
 */
async function deleteCategory(page: Page, name: string) {
    const row = categoryRow(page, name);
    await row.click();
    await expect(row).toHaveAttribute('aria-pressed', 'true');
    await categoryTree(page)
        .getByRole('button', { name: '削除', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'モジュール分類を削除しますか',
    });
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
    );
    await expect(dialog).toBeHidden();
    await expect(categoryRow(page, name)).toHaveCount(0);
}

test('「総務」を選んで「業務モジュールの追加」の Dialog で「プロジェクト」「課題」を選択して更新すると、木の「総務」に 2 件が入り、業務のサイドバーに見出し「総務」が表示される', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/categories');
    const add = categoryTree(page).getByRole('button', {
        name: '業務モジュールの追加',
        exact: true,
    });
    await expect(add.locator('svg.lucide-folder-input')).toBeVisible();
    // 分類を選ぶまで押せない
    await expect(add).toBeDisabled();
    const row = categoryRow(page, '総務');
    await row.click();
    await expect(row).toHaveAttribute('aria-pressed', 'true');

    await add.click();
    const dialog = page.getByRole('dialog', { name: '業務モジュールの追加' });
    await expect(dialog).toContainText(
        '『総務』に追加する業務モジュールを選択してください。',
    );
    const list = dialog.getByRole('group', {
        name: '業務モジュール',
        exact: true,
    });
    await expect(list.locator('label')).toHaveText(
        UNCATEGORIZED.afterCategoryDelete,
    );
    for (const name of ['備品台帳', 'プロジェクト', '課題']) {
        await expect(
            list.getByRole('checkbox', { name, exact: true }),
        ).not.toBeChecked();
    }

    await list
        .getByRole('checkbox', { name: 'プロジェクト', exact: true })
        .click();
    await list.getByRole('checkbox', { name: '課題', exact: true }).click();
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '更新', exact: true }),
    );

    await expect(
        page.getByText('モジュール分類の業務モジュールを更新しました', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();
    await expect(modulesIn(page, '総務')).toHaveText(['プロジェクト', '課題']);
    await expect(
        page.getByRole('list', { name: '分類なしの業務モジュール' }),
    ).toHaveText(UNCATEGORIZED.afterAdd);

    // 開き直すと、その分類の中の 2 件にチェックが付いている
    await add.click();
    await expect(
        list.getByRole('checkbox', { name: 'プロジェクト', exact: true }),
    ).toBeChecked();
    await expect(
        list.getByRole('checkbox', { name: '課題', exact: true }),
    ).toBeChecked();
    await expect(
        list.getByRole('checkbox', { name: '備品台帳', exact: true }),
    ).not.toBeChecked();
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toBeHidden();

    // 業務のサイドバー: 見出し「総務」の区画に 2 件が並び、見出しなしの区画のリンクは「備品台帳」だけ
    await expectUntitledLinks(
        page,
        ['備品台帳', 'プロジェクト', '課題'],
        ['備品台帳'],
    );
    await expect(sidebarSection(page, '総務').getByRole('link')).toHaveText([
        'プロジェクト',
        '課題',
    ]);
});

test('取っ手をマウスで別の分類の行へドラッグすると、Ctrl なしは移動、Ctrl を押しながらはコピーになり、最後に分類を消すとすべて「業務」の直下に戻る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/categories');

    // 「分類なし」の「備品台帳」を「総務」へ = 「総務」の末尾に入り、「分類なし」の表示中の行は初期データの会議だけになる(後ろに非表示の申請書の業務モジュール 2 つ)
    await dragToCategory(
        page,
        page.getByRole('button', {
            name: '備品台帳の並び順を変更',
            exact: true,
        }),
        '総務',
        '/records/admin/modules/equipment/categories',
    );
    await expect(modulesIn(page, '総務')).toHaveText([
        'プロジェクト',
        '課題',
        '備品台帳',
    ]);
    await expect(
        page.getByRole('list', { name: '分類なしの業務モジュール' }),
    ).toHaveText(UNCATEGORIZED.onlyMeeting);

    // Ctrl を押しながら「総務」の「課題」を「営業」へ = 両方にある
    await createCategory(page, '営業');
    await dragToCategory(
        page,
        modulesIn(page, '総務')
            .filter({ hasText: '課題' })
            .getByRole('button', { name: '課題の並び順を変更', exact: true }),
        '営業',
        '/records/admin/modules/project_issue/categories',
        true,
    );
    await expect(modulesIn(page, '総務')).toHaveText([
        'プロジェクト',
        '課題',
        '備品台帳',
    ]);
    await expect(modulesIn(page, '営業')).toHaveText(['課題']);

    // Ctrl なしで「総務」の「備品台帳」を「営業」へ = 「総務」から消え「営業」の末尾に入る
    await dragToCategory(
        page,
        modulesIn(page, '総務').getByRole('button', {
            name: '備品台帳の並び順を変更',
            exact: true,
        }),
        '営業',
        '/records/admin/modules/equipment/categories',
    );
    await expect(modulesIn(page, '総務')).toHaveText(['プロジェクト', '課題']);
    await expect(modulesIn(page, '営業')).toHaveText(['課題', '備品台帳']);
    await page.reload();
    await expect(modulesIn(page, '総務')).toHaveText(['プロジェクト', '課題']);
    await expect(modulesIn(page, '営業')).toHaveText(['課題', '備品台帳']);

    // 「営業」の「備品台帳」をつかみ、「総務」の見出しの上(囲まれる)を通って木の外(ツールバー)へ出し、囲みが消えてから離すと、何も送られない
    const sent: string[] = [];
    const recordWrite = (request: Request) => {
        if (request.method() !== 'GET') {
            sent.push(`${request.method()} ${request.url()}`);
        }
    };
    page.on('request', recordWrite);
    await dropOutsideTree(
        page,
        modulesIn(page, '営業').getByRole('button', {
            name: '備品台帳の並び順を変更',
            exact: true,
        }),
        '備品台帳',
        '総務',
    );
    await expect(modulesIn(page, '総務')).toHaveText(['プロジェクト', '課題']);
    await expect(modulesIn(page, '営業')).toHaveText(['課題', '備品台帳']);
    await page.reload();
    await expect(modulesIn(page, '総務')).toHaveText(['プロジェクト', '課題']);
    await expect(modulesIn(page, '営業')).toHaveText(['課題', '備品台帳']);
    page.off('request', recordWrite);
    expect(sent).toEqual([]);

    // 分類を消して、すべての業務モジュールを「業務」の直下に戻す
    await deleteCategory(page, '総務');
    await deleteCategory(page, '営業');
    await expectUntitledLinks(
        page,
        ['備品台帳', 'プロジェクト', '課題'],
        ['プロジェクト', '課題', '備品台帳'],
    );
});
