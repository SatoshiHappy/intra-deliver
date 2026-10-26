import { describe, expect, it } from 'vite-plus/test';
import { initialApplicationTargetState } from './applicationTarget';
import {
    applicationTargetFlowOf,
    cancelApplicationTarget,
    applicantChanged,
    reflectApplicationTarget,
} from './applicationTargetFlow';
import {
    APPLICATION_TARGET_FIELD,
    snapshotFor,
    flowSetup,
    opened,
    picked,
    planModule,
    recordOf,
} from './applicationTarget.testing';
import { clearRecordReferences } from './applyApplicant';

describe('申請対象を選んだとき', () => {
    it('写しの値の入口へ今の申請者で要求し、取っている間は busy、応答で写して決まる', async () => {
        const { state, requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));

        expect(requests.map((request) => request.url)).toEqual([
            '/values/5?applicant=10',
        ]);
        expect(lastView()).toEqual({ busy: true, confirm: null });

        await requests[0].succeed(snapshotFor('新製品'));

        expect(state.record.values.name).toBe('新製品');
        expect(lastView()).toEqual({ busy: false, confirm: null });
    });

    it('空にしたら要求しない。同じ申請対象を選び直しても要求しない', async () => {
        const { requests, choose } = flowSetup(opened);

        choose(null);
        choose(picked(9, '開いた時'));
        choose(picked(9, '開いた時'));

        expect(requests).toHaveLength(1);
    });

    it('申請対象の項目が無い画面では何もしない', async () => {
        const { requests, choose, state } = flowSetup(opened, null);

        choose(picked(5));

        expect(requests).toEqual([]);
        expect(state.views).toEqual([]);
    });
});

describe('写す前の確かめ', () => {
    const typed = recordOf({ project: picked(9), name: '手で入れた名前' });

    it('違う入力済みの値があれば値を変えずに確かめを出し、取っている間の印は外れる', async () => {
        const { state, requests, choose, lastView } = flowSetup(typed);

        choose(picked(5));
        await requests[0].succeed(snapshotFor('新製品'));

        expect(state.record.values.name).toBe('手で入れた名前');
        expect(lastView()?.busy).toBe(false);
        expect(
            lastView()?.confirm?.changes.map((change) => change.field.code),
        ).toEqual(['name']);
    });

    it('「反映」で写して申請対象が決まる(確かめは閉じる)。次の 404 の戻し先は反映した申請対象', async () => {
        const { state, requests, choose, applicationTarget, ports, lastView } =
            flowSetup(typed);

        choose(picked(5));
        await requests[0].succeed(snapshotFor('新製品'));
        reflectApplicationTarget(applicationTarget, ports);

        expect(state.record.values.name).toBe('新製品');
        expect(lastView()?.confirm).toBeNull();

        choose(picked(6));
        await requests[1].fail();

        expect(state.record.values.project).toEqual(picked(5));
    });

    it('「キャンセル」で申請対象も欄の値も変えない(選ぶ前の申請対象に戻る)', async () => {
        const { state, requests, choose, applicationTarget, ports, lastView } =
            flowSetup(typed);

        choose(picked(5));
        await requests[0].succeed(snapshotFor('新製品'));
        cancelApplicationTarget(applicationTarget, ports);

        expect(state.record).toEqual(typed);
        expect(lastView()?.confirm).toBeNull();
    });
});

describe('403・404 と古い応答', () => {
    it('404: 申請対象だけを最後に決まった申請対象へ戻し、ほかの欄(写しの後に手で直した値を含む)は変えず、トーストを 1 回出す', async () => {
        const { state, requests, choose } = flowSetup(opened);

        choose(picked(5));
        await requests[0].succeed(snapshotFor('新製品'));
        state.record = recordOf({
            ...state.record.values,
            name: '手で直した名前',
        });
        choose(picked(6));
        await requests[1].fail();

        expect(state.record.values.project).toEqual(picked(5));
        expect(state.record.values.name).toBe('手で直した名前');
        expect(state.denied).toBe(1);
    });
});

describe('403・404 と古い応答(続き)', () => {
    it('応答の順が逆: 後の要求の応答だけを当て、前の要求の応答(404 を含む)はトーストも戻しも写しも無い', async () => {
        const { state, requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));
        choose(picked(6));
        await requests[1].succeed(snapshotFor('六番目', 6));
        await requests[0].fail();
        await requests[0].succeed(snapshotFor('五番目', 5));

        expect(state.record.values.name).toBe('六番目');
        expect(state.record.values.project).toMatchObject({ id: 6 });
        expect(state.denied).toBe(0);
        expect(lastView()?.busy).toBe(false);
    });

    it('後の要求が 404: 最後に決まった申請対象(選び直す前の値)へ戻り、前の要求の応答は当たらない', async () => {
        const { state, requests, choose } = flowSetup(opened);

        choose(picked(5));
        choose(picked(6));
        await requests[1].fail();
        await requests[0].succeed(snapshotFor('五番目', 5));

        expect(state.record.values.project).toEqual(picked(9, '開いた時'));
        expect(state.record.values.name).toBe('');
        expect(state.denied).toBe(1);
    });
});

describe('取得中に空にする・申請者を変える', () => {
    it('取得中に空にする: 前の要求の応答は当たらず(トーストも戻しも無い)、空のまま', async () => {
        const { state, requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));
        choose(null);
        await requests[0].succeed(snapshotFor('新製品'));
        await requests[0].fail();

        expect(state.record.values.project).toBeNull();
        expect(state.record.values.name).toBe('');
        expect(state.denied).toBe(0);
        expect(lastView()?.busy).toBe(false);
    });

    it('取得中に空にしてから選んだレコードが 404: 空に戻る。写しの後に空にしてから選んだレコードが 404 でも空に戻る', async () => {
        const { state, requests, choose } = flowSetup(opened);

        choose(picked(5));
        choose(null);
        choose(picked(6));
        await requests[1].fail();

        expect(state.record.values.project).toBeNull();

        choose(picked(7));
        await requests[2].succeed(snapshotFor('七番目', 7));
        choose(null);
        choose(picked(8));
        await requests[3].fail();

        expect(state.record.values.project).toBeNull();
        expect(state.record.values.name).toBe('七番目');
    });
});

describe('取得中に空にする・申請者を変える(続き)', () => {
    it('取得中に申請者を変える: 前の要求の応答は当たらず、そのあと選んだレコードが 404 でも空に戻る', async () => {
        const { state, requests, choose, applicationTarget, ports, lastView } =
            flowSetup(opened);

        choose(picked(5));
        applicantChanged(applicationTarget, ports, 11, () =>
            ports.updateRecord((record) =>
                clearRecordReferences(planModule, record),
            ),
        );
        await requests[0].fail();

        expect(lastView()?.busy).toBe(false);
        expect(state.denied).toBe(0);

        choose(picked(6));
        await requests[1].fail();

        expect(state.record.values.project).toBeNull();
        expect(state.denied).toBe(1);
    });
});

describe('開いた時の確かめ', () => {
    it('開いた直後の確かめの「キャンセル」は、開いた時の値のまま(申請対象も欄の値も変えない)', async () => {
        const current = recordOf({
            project: picked(5, 'P5'),
            name: '手で入れた名前',
        });
        const { state, ports } = flowSetup(current);
        const confirm = {
            applicationTarget: picked(5, 'P5'),
            snapshot: snapshotFor('新製品'),
            changes: [],
        };
        const applicationTarget = {
            ...applicationTargetFlowOf(APPLICATION_TARGET_FIELD, {
                record: current,
                confirm,
            }),
        };

        cancelApplicationTarget(applicationTarget, ports);

        expect(state.record).toEqual(current);
        expect(applicationTarget.state).toEqual(
            initialApplicationTargetState(picked(5, 'P5')),
        );
    });
});
