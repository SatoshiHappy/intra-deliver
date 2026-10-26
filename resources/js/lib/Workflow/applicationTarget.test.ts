import { describe, expect, it } from 'vite-plus/test';
import {
    confirmOf,
    initialApplicationTargetState,
    isApplicationTargetBusy,
    openingOf,
    applicationTargetAnswered,
    applicationTargetCleared,
    applicationTargetPicked,
    applicationTargetSettled,
} from './applicationTarget';
import type { ApplicationTargetState } from './applicationTarget';
import {
    APPLICATION_TARGET_FIELD,
    snapshotOf,
    picked,
    planModule,
    recordOf,
    reference,
} from './applicationTarget.testing';
import type { WorkflowApplyProps } from '@/types';

const first = picked(1);
const second = picked(2);

/** 最後に決まった申請対象が settled の状態で、選んだ値の要求を送った */
function sent(
    settled: ReturnType<typeof picked> | null,
    ...targets: ReturnType<typeof picked>[]
) {
    let state: ApplicationTargetState = initialApplicationTargetState(settled);
    const requests: number[] = [];

    for (const applicationTarget of targets) {
        const result = applicationTargetPicked(state, applicationTarget);
        state = result.state;
        requests.push(result.request ?? 0);
    }

    return { state, requests };
}

describe('取得中の状態(最後に送った要求の応答だけを当てる)', () => {
    it('選ぶたびに要求の番号が進み、取っている間は busy。応答を受けたら busy でなくなる', () => {
        const { state, requests } = sent(null, first);

        expect(requests).toEqual([1]);
        expect(isApplicationTargetBusy(state)).toBe(true);
        expect(
            isApplicationTargetBusy(
                applicationTargetAnswered(state, 1, 'ok').state,
            ),
        ).toBe(false);
    });

    it('応答の順が逆: 前の要求の応答(成功も 403・404 も)は当てず、後の要求の応答だけを当てる', () => {
        const { state } = sent(null, first, second);

        expect(applicationTargetAnswered(state, 1, 'ok').next).toEqual({
            kind: 'ignore',
        });
        expect(applicationTargetAnswered(state, 1, 'failed').next).toEqual({
            kind: 'ignore',
        });
        expect(applicationTargetAnswered(state, 2, 'ok').next).toEqual({
            kind: 'snapshot',
            applicationTarget: second,
        });
    });

    it('前の要求を無視しても後の要求の待ちは残る(状態を変えない)', () => {
        const { state } = sent(null, first, second);

        expect(applicationTargetAnswered(state, 1, 'ok').state).toBe(state);
    });

    it('後の要求が 404: 最後に決まった申請対象へ戻す(待っている間に選んだ値には戻さない)', () => {
        const { state } = sent(picked(9), first, second);
        const result = applicationTargetAnswered(state, 2, 'failed');

        expect(result.next).toEqual({ kind: 'revert', to: picked(9) });
        expect(isApplicationTargetBusy(result.state)).toBe(false);
    });
});

describe('取れなかったとき・取得中に空にする・申請者を変える', () => {
    it('取得中に空にする: 前の要求の応答は当てず、空が最後に決まった申請対象になる', () => {
        const { state } = sent(picked(9), first);
        const emptied = applicationTargetPicked(state, null);

        expect(emptied.request).toBeNull();
        expect(emptied.state.settled).toBeNull();
        expect(applicationTargetAnswered(emptied.state, 1, 'ok').next).toEqual({
            kind: 'ignore',
        });
        expect(
            applicationTargetAnswered(emptied.state, 1, 'failed').next,
        ).toEqual({
            kind: 'ignore',
        });
    });

    it('取得中に空にしてから選んだレコードが 404: 空に戻る', () => {
        const { state } = sent(picked(9), first);
        const again = applicationTargetPicked(
            applicationTargetPicked(state, null).state,
            second,
        );

        expect(
            applicationTargetAnswered(again.state, again.request ?? 0, 'failed')
                .next,
        ).toEqual({
            kind: 'revert',
            to: null,
        });
    });

    it('取れなかったときも、最後に決まった申請対象へ戻す', () => {
        const { state } = sent(picked(9), first);

        expect(applicationTargetAnswered(state, 1, 'failed').next).toEqual({
            kind: 'revert',
            to: picked(9),
        });
    });
});

describe('取得中に空にする・申請者を変える(続き)', () => {
    it('写しの後に空にしてから選んだレコードが 404: 空に戻る', () => {
        const copied = applicationTargetSettled(
            applicationTargetAnswered(sent(null, first).state, 1, 'ok').state,
            first,
        );
        const emptied = applicationTargetPicked(copied, null).state;
        const again = applicationTargetPicked(emptied, second);

        expect(emptied.settled).toBeNull();
        expect(
            applicationTargetAnswered(again.state, again.request ?? 0, 'failed')
                .next,
        ).toEqual({
            kind: 'revert',
            to: null,
        });
    });

    it('取得中に申請者を変える: 前の要求の応答は当てず、そのあと選んだレコードが 404 でも空に戻る', () => {
        const { state } = sent(picked(9), first);
        const cleared = applicationTargetCleared(state);
        const again = applicationTargetPicked(cleared, second);

        expect(isApplicationTargetBusy(cleared)).toBe(false);
        expect(applicationTargetAnswered(cleared, 1, 'ok').next).toEqual({
            kind: 'ignore',
        });
        expect(
            applicationTargetAnswered(again.state, again.request ?? 0, 'failed')
                .next,
        ).toEqual({
            kind: 'revert',
            to: null,
        });
    });

    it('写しを当てて決まった申請対象が、次の戻す先になる', () => {
        const copied = applicationTargetSettled(sent(null, first).state, first);
        const again = applicationTargetPicked(copied, second);

        expect(
            applicationTargetAnswered(again.state, again.request ?? 0, 'failed')
                .next,
        ).toEqual({
            kind: 'revert',
            to: first,
        });
    });
});

/** 開いた時の props(申請対象の項目は project) */
function props(overrides: Partial<WorkflowApplyProps>): WorkflowApplyProps {
    return {
        mode: 'new',
        module: planModule,
        targetField: APPLICATION_TARGET_FIELD,
        targetValues: null,
        targetEntry: null,
        ...overrides,
    } as WorkflowApplyProps;
}

const snapshot = snapshotOf({
    project: reference(5, '新製品'),
    name: '新製品の計画',
});
const entry = { module: 'project', record: 5 };

describe('開いた時の写し(申請対象の値の写しの表の「開いた時」の 7 行)', () => {
    it('業務レコードの詳細から: 初期値に写し、確かめない(入力済みの値があっても。開く前に入力は無い)', () => {
        const opening = openingOf(
            props({ targetValues: snapshot, targetEntry: entry }),
            recordOf(),
        );

        expect(opening.confirm).toBeNull();
        expect(opening.record.values.name).toBe('新製品の計画');
        expect(opening.record.values.project).toMatchObject({ id: 5 });
    });

    it('そのものを申請する・入っている・できる・違う入力済みの値がある: 確かめてから写す(開いた直後に確かめ、値は開いた時のまま)', () => {
        const current = recordOf({
            project: picked(5, '新製品'),
            name: '旧名',
        });
        const opening = openingOf(
            props({ mode: 'record', targetValues: snapshot }),
            current,
        );

        expect(opening.record).toBe(current);
        expect(
            opening.confirm?.changes.map((change) => change.field.code),
        ).toEqual(['name']);
        expect(opening.confirm?.applicationTarget).toEqual(picked(5, '新製品'));
    });
});

describe('開いた時の写し(続き)', () => {
    it('そのものを申請する・入っている・できる・違う入力済みの値が無い: 黙って写す', () => {
        const current = recordOf({ project: picked(5, '新製品') });
        const opening = openingOf(
            props({ mode: 'record', targetValues: snapshot }),
            current,
        );

        expect(opening.confirm).toBeNull();
        expect(opening.record.values.name).toBe('新製品の計画');
    });

    it('そのものを申請する・入っている・できない(写しの値が無い)・空・新規・一時保存・再申請: 写さない', () => {
        const current = recordOf({ name: '旧名' });

        for (const mode of ['record', 'new', 'draft', 'reapply'] as const) {
            expect(openingOf(props({ mode }), current)).toEqual({
                record: current,
                confirm: null,
            });
        }
    });

    it('一時保存・再申請は、写しの値があっても開いた時に写さない', () => {
        const current = recordOf({ name: '旧名' });

        for (const mode of ['draft', 'reapply'] as const) {
            expect(
                openingOf(props({ mode, targetValues: snapshot }), current),
            ).toEqual({
                record: current,
                confirm: null,
            });
        }
    });
});

describe('開いた後の写し(確かめるか)', () => {
    it('違う入力済みの値があれば確かめる内容を返し、無ければ null(そのまま写す)', () => {
        const applicationTarget = picked(5, '新製品');

        expect(
            confirmOf(
                planModule,
                APPLICATION_TARGET_FIELD,
                recordOf({ name: '旧名' }),
                snapshot,
                applicationTarget,
            ),
        ).toMatchObject({
            applicationTarget,
            snapshot,
        });
        expect(
            confirmOf(
                planModule,
                APPLICATION_TARGET_FIELD,
                recordOf(),
                snapshot,
                applicationTarget,
            ),
        ).toBeNull();
        expect(
            confirmOf(
                planModule,
                APPLICATION_TARGET_FIELD,
                recordOf({ name: '新製品の計画' }),
                snapshot,
                applicationTarget,
            ),
        ).toBeNull();
    });
});
