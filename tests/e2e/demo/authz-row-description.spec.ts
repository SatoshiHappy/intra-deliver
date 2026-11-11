import { expect, test } from '../support/test';
import { ADMINISTRATOR, openOverviewAs } from './authz-editor-support';

// 認可設定の画面の行の説明(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3 の「先頭の列(認可対象)」): 説明のある行(ルートの束)の
// 名前の右の「?」にポインタを合わせる・フォーカスすると吹き出しで説明が出る。説明の無い行(フォルダ)には「?」が無い。
// システム管理者に切り替えて確かめる。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

/** 束「申請操作」の説明(初期データの ja の文) */
const CASES_DESCRIPTION =
    '参照を許されたフローの申請を一覧表示し、操作を許されたフローでは処理担当者の変更・ステップの移動・削除などができます';

test('束の「?」にポインタを合わせる・フォーカスすると説明の吹き出しが出て、フォルダには「?」が無い', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    const cases = page
        .getByRole('rowheader', { name: '申請操作', exact: true })
        .getByRole('button', { name: '申請操作の説明', exact: true });
    const tooltip = page.getByRole('tooltip');

    await cases.hover();
    await expect(tooltip).toHaveText(CASES_DESCRIPTION);
    // Radix の Tooltip は、ポインタが離れた後に来る次の pointermove で閉じる(離れた時の移動 1 回では、時機により閉じない)ので、閉じるまで動かす
    await expect(async () => {
        await page.mouse.move(0, 0);
        await page.mouse.move(1, 1);
        await expect(tooltip).toHaveCount(0, { timeout: 500 });
    }).toPass({ timeout: 5000 });

    // 判定タブでも同じ(キーボードのフォーカス)
    await page.getByRole('tab', { name: '判定' }).click();
    await cases.focus();
    await expect(tooltip).toHaveText(CASES_DESCRIPTION);

    await expect(
        page
            .getByRole('rowheader', { name: 'ワークフロー', exact: true })
            .getByRole('button', { name: 'ワークフローの説明' }),
    ).toHaveCount(0);
});
