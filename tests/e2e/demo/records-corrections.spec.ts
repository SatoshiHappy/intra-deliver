import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';

// 業務データの画面の誤りの出し方・起動・検索欄・選択欄・参照の候補・文字数(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.1・2.2、
// 管理の画面.md 3 節の冒頭、画面/README.md 4 節、設計/画面の意匠/トークンとダーク.md 8 節、申請の画面の文字数 = 設計/ワークフロー/画面/申請と案件.md 3 節)。システム管理者に切り替えて業務モジュールを作る筋があるので demo の持ち物。
// 業務モジュールは消せないので、自分の業務モジュール「訂正確認」を最初の筋で作って使う。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/**
 * ゲストから利用者メニューのデモユーザー切替で role に切り替えて、path を開く。label は切り替えた後の表示(氏名とカレント組織)。
 */
async function openAs(page: Page, role: string, label: string, path: string) {
    await page.goto('/');
    await page
        .getByRole('button', { name: '利用者メニュー：ゲスト', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: role }),
    );
    await expect(
        page.getByRole('button', {
            name: `利用者メニュー：${label}`,
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(path);
}

/**
 * ゲストからシステム管理者に切り替えて、path を開く。
 */
async function openAsAdministrator(page: Page, path: string) {
    await openAs(page, 'システム管理者', '伊藤 涼子（経営企画室）', path);
}

/**
 * 管理の項目の追加の画面を開き、コード・名前・型を入れる。
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

/**
 * 項目を、型だけ決めて(ほかは既定のまま)追加する。
 */
async function addField(page: Page, code: string, name: string, type: string) {
    await page.goto('/records/admin/modules/correction');
    const form = await startField(page, code, name, type);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/correction',
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 明細「マイルストーン」に行を足し、名称と期日を入れる(期日が空の行は、送るとその行の期日に誤りが出る)。
 */
async function fillMilestones(form: Locator, rows: [string, string][]) {
    for (const [index, [name, dueDate]] of rows.entries()) {
        const position = `(${index + 1} 行目)`;
        await form
            .getByRole('button', { name: '行を追加', exact: true })
            .click();
        await form
            .getByRole('textbox', { name: `名称${position}`, exact: true })
            .fill(name);
        if (dueDate !== '') {
            await form
                .getByLabel(`期日${position}`, { exact: true })
                .fill(dueDate);
        }
    }
}

/**
 * 明細の 2 行目の期日の誤りを出したフォームで、行の並びを変えると、どの行にも誤りが残らないことを確かめる。
 * 誤りのキーは添字で引くので、行の移動・削除で別の行に付き替わりやすい。
 */
async function expectRowErrorsCleared(
    form: Locator,
    reorder: string,
    rowsAfter: number,
) {
    const dueDate = (row: number) =>
        form.getByLabel(`期日(${row} 行目)`, { exact: true });
    await expect(dueDate(2)).toHaveAttribute('aria-invalid', 'true');

    await form.getByRole('button', { name: reorder, exact: true }).click();

    for (let row = 1; row <= rowsAfter; row++) {
        await expect(dueDate(row)).not.toHaveAttribute('aria-invalid', 'true');
    }
}

/**
 * 畳まれている検索欄を、見出しで開く。
 */
async function openSearch(page: Page) {
    const toggle = page.getByRole('button', { name: '検索条件', exact: true });
    // 開閉は業務モジュールによらず覚えるので、ほかの一覧で開いたままのときは押さない
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
        await toggle.click();
    }
    await expect(
        page.getByRole('search', { name: 'レコードの検索' }),
    ).toBeVisible();
}

test('準備: 業務モジュール「訂正確認」を作り、件名・備考・確認済の項目を足す', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/create');
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill('correction');
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill('訂正確認');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '作成', exact: true }),
        '/records/admin/modules/correction',
    );

    const title = await startField(page, 'title', '件名', '文字列(1 行)');
    await title
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    await title
        .getByRole('checkbox', { name: '表示の項目', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        title.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/correction',
    );

    await addField(page, 'memo', '備考', '文字列(1 行)');
    await addField(page, 'done', '確認済', '真偽');
});

test('規則の条件の行を消すと、前の行の誤りが残った行に出ない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    await page.getByRole('button', { name: '規則を追加', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '規則の追加' });
    await dialog
        .getByRole('combobox', { name: '対象の項目', exact: true })
        .click();
    await page.getByRole('option', { name: '備考', exact: true }).click();

    // 1 行目は値を空のまま、2 行目は値を入れる
    await dialog
        .getByRole('combobox', { name: '条件の項目(1 行目)', exact: true })
        .click();
    await page.getByRole('option', { name: '件名', exact: true }).click();
    await dialog
        .getByRole('button', { name: '条件を追加', exact: true })
        .click();
    await dialog
        .getByRole('combobox', { name: '条件の項目(2 行目)', exact: true })
        .click();
    await page.getByRole('option', { name: '件名', exact: true }).click();
    await dialog
        .getByRole('textbox', { name: '条件の値(2 行目)', exact: true })
        .fill('x');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '追加', exact: true }),
    );

    // 誤りは 1 行目(キー condition.all.0.value)だけに出る
    const message = dialog.getByText('値を入力してください', { exact: true });
    await expect(message).toHaveCount(1);
    await expect(
        dialog.getByRole('textbox', { name: '条件の値(1 行目)', exact: true }),
    ).toHaveAttribute('aria-invalid', 'true');

    // 1 行目を消すと、残った行(元の 2 行目。添字 0 になる)に元の 1 行目の誤りが移らない
    await dialog
        .getByRole('button', { name: '条件を削除(1 行目)', exact: true })
        .click();

    const remaining = dialog.getByRole('textbox', {
        name: '条件の値(1 行目)',
        exact: true,
    });
    await expect(remaining).toHaveValue('x');
    await expect(remaining).not.toHaveAttribute('aria-invalid', 'true');
    await expect(message).toHaveCount(0);
});

test('選択肢の行を並べ替えると、その表のサーバーの誤りが消える', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const form = await startField(page, 'kind', '区分', '選択');
    await form
        .getByRole('textbox', { name: '選択肢のコード(1 行目)', exact: true })
        .fill('one');
    await form
        .getByRole('button', { name: '選択肢を追加', exact: true })
        .click();
    await form
        .getByRole('textbox', { name: '選択肢のコード(2 行目)', exact: true })
        .fill('two');
    await form
        .getByRole('textbox', { name: '選択肢の名称(2 行目)', exact: true })
        .fill('二');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
    );

    // 1 行目の名称が空なので、サーバーが options.0.name の誤りを返す
    const names = [1, 2].map((row) =>
        form.getByRole('textbox', {
            name: `選択肢の名称(${row} 行目)`,
            exact: true,
        }),
    );
    await expect(names[0]).toHaveAttribute('aria-invalid', 'true');

    await form
        .getByRole('button', { name: '下へ(1 行目)', exact: true })
        .click();

    // 並びが変わったので、添字で引く誤りは残らない(元の 1 行目は 2 行目に動く)
    await expect(names[0]).not.toHaveAttribute('aria-invalid', 'true');
    await expect(names[1]).not.toHaveAttribute('aria-invalid', 'true');
});

test('入力欄を開いた後に項目が消されると、欄に結びつかない誤りが入力欄の上に出てフォーカスが当たり、2 回目は通る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/data/correction/create');
    const form = page.getByRole('form', { name: '訂正確認の入力' });
    const title = form.getByRole('textbox', { name: '件名', exact: true });
    await expect(
        form.getByRole('textbox', { name: '備考', exact: true }),
    ).toBeVisible();

    // 開いたままのフォームの裏で、別の画面が項目「備考」を消す
    const other = await page.context().newPage();
    await other.goto('/records/admin/modules/correction');
    await other
        .getByRole('button', { name: '備考を削除', exact: true })
        .click();
    await submitAndExpectUrl(
        other,
        other
            .getByRole('dialog', { name: '項目の削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        other.getByText('項目を削除しました', { exact: true }),
    ).toBeVisible();
    await other.close();

    await title.fill('訂正の確認');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );

    // 備考の欄は、422 で戻った後の props からも消える。誤り(values.memo)は欄に結びつかないので、入力欄のいちばん上に出る
    const alert = form
        .getByRole('alert')
        .filter({ hasText: '項目がありません' });
    await expect(alert).toBeVisible();
    await expect(alert).toBeFocused();
    const alertBox = await alert.boundingBox();
    const titleBox = await title.boundingBox();
    expect(alertBox?.y).toBeLessThan(titleBox?.y ?? 0);

    // 消えた項目は次の送信から送らないので、2 回目は通る
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/correction\/\d+$/,
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
});

test('localStorage が使えなくても、登録のトースト「登録しました」が出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    const blocked = await page.context().newPage();
    await blocked.addInitScript(() => {
        // 保存先を無効にしたブラウザ(参照するだけで例外)
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() {
                throw new DOMException('denied', 'SecurityError');
            },
        });
    });

    await blocked.goto('/records/data/correction/create');
    const form = blocked.getByRole('form', { name: '訂正確認の入力' });
    await form
        .getByRole('textbox', { name: '件名', exact: true })
        .fill('保存先なし');
    await submitAndExpectUrl(
        blocked,
        form.getByRole('button', { name: '登録', exact: true }),
    );

    await expect(
        blocked.getByText('登録しました', { exact: true }),
    ).toBeVisible();
    await blocked.close();
});

test('文字列の検索欄に __NONE__ を打つと検索の語として送られて欄に残り、真偽は「指定なし」で条件が消える', async ({
    page,
}) => {
    // 画面の選択の内部値と同じ語を、文字列の欄の語として捨てない(docs の 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節)
    await page.goto('/records/data/project');
    await openSearch(page);
    const name = page
        .getByRole('search', { name: 'レコードの検索' })
        .getByRole('textbox', { name: 'プロジェクト名', exact: true });
    await name.fill('__NONE__');
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(page).toHaveURL(/\?(.*&)?filter%5Bname%5D=__NONE__(&|$)/);
    await expect(name).toHaveValue('__NONE__');

    await openAsAdministrator(page, '/records/data/correction');
    await openSearch(page);
    const done = page.getByRole('combobox', { name: '確認済', exact: true });
    await done.click();
    await page.getByRole('option', { name: 'はい', exact: true }).click();
    await page.getByRole('button', { name: '検索', exact: true }).click();
    await expect(page).toHaveURL(/\?(.*&)?filter%5Bdone%5D=true(&|$)/);

    await done.click();
    await page.getByRole('option', { name: '指定なし', exact: true }).click();
    await page.getByRole('button', { name: '検索', exact: true }).click();
    await expect(page).toHaveURL('/records/data/correction');
});

test('業務のフォームで明細の行を削除・移動すると、添字のずれた行に誤りが残らない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    for (const [reorder, rowsAfter, kept] of [
        ['行を削除(1 行目)', 2, '2027-03-01'],
        ['下へ(1 行目)', 3, '2027-01-01'],
    ] as const) {
        await page.goto('/records/data/project/create');
        const form = page.getByRole('form', { name: 'プロジェクトの入力' });
        await fillMilestones(form, [
            ['要件', '2027-01-01'],
            ['設計', ''],
            ['実装', '2027-03-01'],
        ]);
        // ほかの必須の項目も空なので、登録は 422 で戻る
        await submitAndExpectUrl(
            page,
            form.getByRole('button', { name: '登録', exact: true }),
        );

        await expectRowErrorsCleared(form, reorder, rowsAfter);
        // 削除なら元の 3 行目、下へなら元の 1 行目が 2 行目に来る
        await expect(
            form.getByLabel('期日(2 行目)', { exact: true }),
        ).toHaveValue(kept);
    }
});

test('申請の画面で明細の行を削除・移動すると、添字のずれた行に誤りが残らない', async ({
    page,
}) => {
    await openAs(page, '申請者', '佐々木 陽太（東京営業課）', '/');
    for (const [reorder, rowsAfter] of [
        ['行を削除(1 行目)', 2],
        ['下へ(1 行目)', 3],
    ] as const) {
        await page.goto('/workflow/flows/project_plan/apply');
        const form = page.getByRole('form', {
            name: 'プロジェクト計画の承認の入力',
        });
        // 申請できる版の日にする(demo/workflow.spec.ts の申請の筋と同じ日)
        await fillVersionTwoBaseDate(form);
        await fillMilestones(form, [
            ['要件', '2027-01-01'],
            ['設計', ''],
            ['実装', '2027-03-01'],
        ]);
        // 必須の項目が空なので、申請は 422 で戻る(案件は作られない)
        await form.getByRole('button', { name: '申請', exact: true }).click();
        await submitAndExpectUrl(
            page,
            page
                .getByRole('dialog', { name: '申請しますか？' })
                .getByRole('button', { name: '決定', exact: true }),
        );

        await expectRowErrorsCleared(form, reorder, rowsAfter);
    }
});

test('書くレコード自身は、自分の業務モジュールを指す項目の候補に出ず、検索欄の候補には出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const field = await startField(page, 'related', '関連', '他モジュール参照');
    await field.getByRole('combobox', { name: '参照先', exact: true }).click();
    await page.getByRole('option', { name: '訂正確認', exact: true }).click();
    await submitAndExpectUrl(
        page,
        field.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/correction',
    );

    await page.goto('/records/data/correction/create');
    const createForm = page.getByRole('form', { name: '訂正確認の入力' });
    await createForm
        .getByRole('textbox', { name: '件名', exact: true })
        .fill('自分の行');
    await submitAndExpectUrl(
        page,
        createForm.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/correction\/\d+$/,
    );

    await page.goto(`${page.url()}/edit`);
    await page
        .getByRole('form', { name: '訂正確認の入力' })
        .getByRole('combobox', { name: '関連', exact: true })
        .click();
    // 候補はほかのレコード(前の筋で登録した「訂正の確認」)だけで、自分は出ない
    await expect(
        page.getByRole('option', { name: '訂正の確認', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('option', { name: '自分の行', exact: true }),
    ).toHaveCount(0);

    await page.goto('/records/data/correction');
    await openSearch(page);
    await page.getByRole('combobox', { name: '関連', exact: true }).click();
    await expect(
        page.getByRole('option', { name: '自分の行', exact: true }),
    ).toBeVisible();
});

test('選択の項目の既定値の欄は、選択肢の表から消した今の値を「<コード>(選択肢にありません)」の選べない項目で出す', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const form = await startField(page, 'tone', '色', '選択');
    await form
        .getByRole('textbox', { name: '選択肢のコード(1 行目)', exact: true })
        .fill('w');
    await form
        .getByRole('textbox', { name: '選択肢の名称(1 行目)', exact: true })
        .fill('白');
    await form
        .getByRole('button', { name: '選択肢を追加', exact: true })
        .click();
    await form
        .getByRole('textbox', { name: '選択肢のコード(2 行目)', exact: true })
        .fill('x');
    await form
        .getByRole('textbox', { name: '選択肢の名称(2 行目)', exact: true })
        .fill('黒');
    const defaultValue = form.getByRole('combobox', {
        name: '既定値',
        exact: true,
    });
    await defaultValue.click();
    await page.getByRole('option', { name: '黒', exact: true }).click();
    await expect(defaultValue).toHaveText('黒');

    await form
        .getByRole('button', { name: '選択肢を削除(2 行目)', exact: true })
        .click();

    // 選択肢に無い既定値は、空の欄に見せず「x(選択肢にありません)」と出す
    await expect(defaultValue).toHaveText('x(選択肢にありません)');
    await defaultValue.click();
    await expect(
        page.getByRole('option', {
            name: 'x(選択肢にありません)',
            exact: true,
        }),
    ).toHaveAttribute('aria-disabled', 'true');
    await expect(
        page.getByRole('option', { name: '白', exact: true }),
    ).toBeVisible();
});

test('選択の項目は、登録のフォームと検索欄で、打って絞って選べ、「選択を解除」で空に戻る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const field = await startField(page, 'grade', '等級', '選択');
    for (const [index, [code, name]] of [
        ['a', 'A級'],
        ['b', 'B級'],
        ['c', 'C級'],
    ].entries()) {
        const position = `(${index + 1} 行目)`;
        if (index > 0) {
            await field
                .getByRole('button', { name: '選択肢を追加', exact: true })
                .click();
        }
        await field
            .getByRole('textbox', {
                name: `選択肢のコード${position}`,
                exact: true,
            })
            .fill(code);
        await field
            .getByRole('textbox', {
                name: `選択肢の名称${position}`,
                exact: true,
            })
            .fill(name);
    }
    await submitAndExpectUrl(
        page,
        field.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/correction',
    );

    await page.goto('/records/data/correction/create');
    const grade = page
        .getByRole('form', { name: '訂正確認の入力' })
        .getByRole('combobox', { name: '等級', exact: true });
    await grade.click();
    await page.getByPlaceholder('検索する語').fill('B');
    await expect(
        page.getByRole('option', { name: 'A級', exact: true }),
    ).toHaveCount(0);
    await page.getByRole('option', { name: 'B級', exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
    await expect(grade).toHaveText('B級');

    await grade.click();
    await page.getByRole('button', { name: '選択を解除', exact: true }).click();
    await expect(grade).toHaveText('選択してください');

    await page.goto('/records/data/correction');
    await openSearch(page);
    const filter = page
        .getByRole('search', { name: 'レコードの検索' })
        .getByRole('combobox', { name: '等級', exact: true });
    await filter.click();
    await page.getByPlaceholder('検索する語').fill('C');
    await expect(
        page.getByRole('option', { name: 'B級', exact: true }),
    ).toHaveCount(0);
    await page.getByRole('option', { name: 'C級', exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
    await page.getByRole('button', { name: '検索', exact: true }).click();
    await expect(page).toHaveURL(/\?(.*&)?filter%5Bgrade%5D=c(&|$)/);
    await expect(filter).toHaveText('C級');

    await filter.click();
    await page.getByRole('button', { name: '選択を解除', exact: true }).click();
    await expect(filter).toHaveText('選択してください');
    await page.getByRole('button', { name: '検索', exact: true }).click();
    await expect(page).toHaveURL('/records/data/correction');
});

test('選択欄は選択肢の名前だけで絞り、選択肢に無い今の値の項目は語を打つと消える', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const form = await startField(page, 'hue', '色相', '選択');
    for (const [index, [code, name]] of [
        ['red', '赤'],
        ['blue', '青'],
    ].entries()) {
        const position = `(${index + 1} 行目)`;
        if (index > 0) {
            await form
                .getByRole('button', { name: '選択肢を追加', exact: true })
                .click();
        }
        await form
            .getByRole('textbox', {
                name: `選択肢のコード${position}`,
                exact: true,
            })
            .fill(code);
        await form
            .getByRole('textbox', {
                name: `選択肢の名称${position}`,
                exact: true,
            })
            .fill(name);
    }
    const defaultValue = form.getByRole('combobox', {
        name: '既定値',
        exact: true,
    });
    await defaultValue.click();
    await page.getByRole('option', { name: '青', exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
    await form
        .getByRole('button', { name: '選択肢を削除(2 行目)', exact: true })
        .click();
    await expect(defaultValue).toHaveText('blue(選択肢にありません)');

    await defaultValue.click();
    const missing = page.getByRole('option', {
        name: 'blue(選択肢にありません)',
        exact: true,
    });
    const red = page.getByRole('option', { name: '赤', exact: true });
    await expect(missing).toBeVisible();
    await expect(red).toBeVisible();

    // コードにだけ含まれる語(red は名前「赤」に無い)では、その選択肢が出ない
    await page.getByPlaceholder('検索する語').fill('red');
    await expect(red).toHaveCount(0);
    await expect(missing).toHaveCount(0);

    // 選択肢に無い今の値の項目は、その文字を打っても、語があるあいだは出ない
    await page.getByPlaceholder('検索する語').fill('blue');
    await expect(missing).toHaveCount(0);
    await expect(
        page.getByText('該当する選択肢はありません', { exact: true }),
    ).toBeVisible();

    await page.getByPlaceholder('検索する語').fill('赤');
    await expect(red).toBeVisible();

    await page.getByPlaceholder('検索する語').fill('');
    await expect(missing).toBeVisible();
});

test('保存済みの選択肢のコードの欄は書き換えられず、足した行のコードの欄は書ける', async ({
    page,
}) => {
    // 等級(grade)は「選択の項目は、登録のフォームと検索欄で…」の筋で作った項目
    await openAsAdministrator(
        page,
        '/records/admin/modules/correction/fields/grade/edit',
    );
    const form = page.getByRole('form', { name: '項目の入力' });
    const savedCode = form.getByRole('textbox', {
        name: '選択肢のコード(1 行目)',
        exact: true,
    });
    await expect(savedCode).toHaveValue('a');
    await expect(savedCode).not.toBeEditable();

    await form
        .getByRole('button', { name: '選択肢を追加', exact: true })
        .click();
    await expect(
        form.getByRole('textbox', {
            name: '選択肢のコード(4 行目)',
            exact: true,
        }),
    ).toBeEditable();
});

test('上限 10 の文字列の項目に「𠮷」を 11 個入れると切られずに超えた数が赤字で出て、送ると欄の下に 422 の誤りが出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/correction');
    const field = await startField(page, 'short', '短文', '文字列(1 行)');
    await field
        .getByRole('textbox', { name: '最大の文字数', exact: true })
        .fill('10');
    await submitAndExpectUrl(
        page,
        field.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/correction',
    );

    await page.goto('/records/data/correction/create');
    const form = page.getByRole('form', { name: '訂正確認の入力' });
    const block = form.getByRole('group', { name: '短文', exact: true });
    const input = block.getByRole('textbox', { name: '短文', exact: true });
    // 数え方は符号位置(サーバーの mb_strlen と同じ)。UTF-16 の単位で切らない
    await input.fill('𠮷'.repeat(11));
    await expect(input).toHaveValue('𠮷'.repeat(11));
    const count = block.getByText('11 / 10(1 文字超過)', { exact: true });
    await expect(count).toBeVisible();
    await expect(count).toHaveClass(/text-destructive/);
    // 超えても送る前の誤りにはしない(誤りの正はサーバー)
    await expect(input).not.toHaveAttribute('aria-invalid', 'true');
    await expect(input).toHaveAccessibleDescription('11 / 10(1 文字超過)');

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        '/records/data/correction/create',
    );
    const message = '短文は 10 文字以内で入力してください';
    await expect(block.getByText(message, { exact: true })).toBeVisible();
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await expect(input).toHaveAccessibleDescription(
        `${message} 11 / 10(1 文字超過)`,
    );
});

test('申請の画面の申請名とコメントの下に「N / 上限」が出る', async ({
    page,
}) => {
    await openAs(
        page,
        '申請者',
        '佐々木 陽太（東京営業課）',
        '/workflow/flows/project_plan/apply',
    );
    const form = page.getByRole('form', {
        name: 'プロジェクト計画の承認の入力',
    });
    const name = form.getByRole('group', { name: '申請名', exact: true });
    await name
        .getByRole('textbox', { name: '申請名', exact: true })
        .fill('件名');
    await expect(name.getByText('2 / 1000', { exact: true })).toBeVisible();

    const comment = form.getByRole('group', { name: 'コメント', exact: true });
    const textarea = comment.getByRole('textbox', {
        name: 'コメント',
        exact: true,
    });
    await expect(comment.getByText('0 / 4000', { exact: true })).toBeVisible();
    await textarea.fill('確認をお願いします');
    await expect(comment.getByText('9 / 4000', { exact: true })).toBeVisible();
    await expect(textarea).toHaveAccessibleDescription(
        '任意の入力です。 9 / 4000',
    );
});
