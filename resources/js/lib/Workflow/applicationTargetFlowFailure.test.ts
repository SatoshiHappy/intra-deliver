import { describe, expect, it } from 'vite-plus/test';
import {
    failedMessageOf,
    fetchOf,
    isHttpFailure,
} from './applicationTargetFetch';
import {
    applicantChanged,
    applicantReselected,
} from './applicationTargetApplicant';
import {
    reflectApplicationTarget,
    recordChanged,
} from './applicationTargetFlow';
import {
    APPLICATION_TARGET_FIELD,
    flowSetup,
    opened,
    picked,
    recordOf,
    snapshotFor,
} from './applicationTarget.testing';
import { withApplicationTarget } from './applicationTargetSnapshot';

/** useHttp が投げる HTTP の失敗(code・response は @inertiajs/core の httpErrors と同じ) */
const httpError = (code: string, status?: number) =>
    Object.assign(
        new Error('http'),
        { code },
        status === undefined ? {} : { response: { status } },
    );

describe('取れなかったときの戻し(403・404・500・通信の失敗・取り消しは同じ)', () => {
    it('申請対象を最後に決まった申請対象へ戻し、トーストを 1 回出し、取得中を解く', async () => {
        const { state, requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));
        await requests[0].fail();

        expect(state.record.values.project).toEqual(picked(9, '開いた時'));
        expect(state.denied).toBe(1);
        expect(lastView()?.busy).toBe(false);
    });

    it('トーストに 403・404 かほかの失敗かを渡す(例外は failed)', async () => {
        const { state, requests, choose, chooseAndWait } = flowSetup(opened);

        choose(picked(5));
        await requests[0].fail('unavailable');
        choose(picked(6));
        await requests[1].fail('failed');
        const crashed = chooseAndWait(picked(7)).catch(() => null);
        await requests[2].crash(new Error('誤り'));
        await crashed;

        expect(state.failures).toEqual(['unavailable', 'failed', 'failed']);
    });

    it('古い要求の失敗は、戻しもトーストも無い(最後に送った要求の応答だけを当てる)', async () => {
        const { state, requests, choose } = flowSetup(opened);

        choose(picked(5));
        choose(picked(6));
        await requests[0].fail();
        await requests[1].succeed(snapshotFor('六番目', 6));
        choose(picked(7));
        choose(null);
        await requests[2].fail();

        expect(state.record.values.project).toBeNull();
        expect(state.denied).toBe(0);
    });
});

describe('取れなかったときの戻し(例外)', () => {
    it('失敗の側(戻し・トースト)で例外が出ても、取得中は解け、例外は上がる', async () => {
        const { state, requests, ports, applicationTarget } = flowSetup(opened);
        const bug = new Error('トーストの誤り');
        const failing = {
            ...ports,
            notifyFailed: () => {
                throw bug;
            },
        };
        const outcome = recordChanged(applicationTarget, failing, (record) =>
            withApplicationTarget(record, APPLICATION_TARGET_FIELD, picked(5)),
        ).then(
            () => null,
            (error: unknown) => error,
        );

        await requests[0].fail();

        expect(await outcome).toBe(bug);
        expect(state.views.at(-1)?.busy).toBe(false);
    });

    it('HTTP の失敗でない例外は、応答を失敗として当てて取得中を解いたうえで、投げ直す', async () => {
        const { state, requests, chooseAndWait } = flowSetup(opened);
        const bug = new Error('要求を組む誤り');
        const outcome = chooseAndWait(picked(5)).then(
            () => null,
            (error: unknown) => error,
        );

        await requests[0].crash(bug);

        expect(await outcome).toBe(bug);
        expect(state.views.at(-1)?.busy).toBe(false);
        expect(state.record.values.project).toEqual(picked(9, '開いた時'));
    });
});

describe('要求の口(fetchOf)', () => {
    it('403・404 の応答は unavailable、ほかの応答の誤り・取り消し・通信の失敗は failed として返す', async () => {
        const cases = [
            [httpError('ERR_HTTP_RESPONSE', 403), 'unavailable'],
            [httpError('ERR_HTTP_RESPONSE', 404), 'unavailable'],
            [httpError('ERR_HTTP_RESPONSE', 500), 'failed'],
            [httpError('ERR_HTTP_RESPONSE', 422), 'failed'],
            [httpError('ERR_CANCELLED'), 'failed'],
            [httpError('ERR_NETWORK'), 'failed'],
        ] as const;

        for (const [error, failure] of cases) {
            expect(isHttpFailure(error)).toBe(true);
            expect(await fetchOf(() => Promise.reject(error))('/x')).toBe(
                failure,
            );
        }
    });

    it('トーストの文は 403・404 とほかの失敗で違う(画面.md の 8 節)', () => {
        expect(failedMessageOf('unavailable')).toBe(
            '申請対象の値を取得できませんでした。',
        );
        expect(failedMessageOf('failed')).toBe(
            '申請対象の値を取得できませんでした。時間をおいて、もう一度選択してください。',
        );
    });

    it('値を返さない応答(検証の誤り)も取れなかった(failed)として扱い、値はそのまま返す', async () => {
        const values = snapshotFor('x');

        expect(await fetchOf(() => Promise.resolve(undefined))('/x')).toBe(
            'failed',
        );
        expect(await fetchOf(() => Promise.resolve(values))('/x')).toBe(values);
    });

    it('HTTP の失敗でない例外は投げ直す(握りつぶさない)', async () => {
        const bug = new Error('誤り');

        expect(isHttpFailure(bug)).toBe(false);
        await expect(fetchOf(() => Promise.reject(bug))('/x')).rejects.toBe(
            bug,
        );
    });
});

describe('同じ tick の続けての変更と、申請者の変更・選び直し', () => {
    it('update は 1 回だけ走り(update の中で数える)、続けて選んでも前の値が古くならない', () => {
        const { state, requests, applicationTarget, ports } = flowSetup(opened);
        let calls = 0;
        const select = (id: number) =>
            recordChanged(applicationTarget, ports, (record) => {
                calls += 1;

                return withApplicationTarget(
                    record,
                    APPLICATION_TARGET_FIELD,
                    picked(id),
                );
            });

        void select(5);
        void select(6);

        expect(calls).toBe(2);
        expect(requests.map((request) => request.url)).toEqual([
            '/values/5?applicant=10',
            '/values/6?applicant=10',
        ]);
        expect(state.record.values.project).toEqual(picked(6));
    });
});

describe('申請者の変更・選び直し', () => {
    it('サーバーが申請者を選び直したら、参照の値を消し、待っている要求の応答は当てない', async () => {
        const { state, requests, choose, applicationTarget, ports, lastView } =
            flowSetup(
                recordOf({
                    project: picked(9),
                    name: '直した名前',
                    department: picked(3),
                }),
            );

        choose(picked(5));
        applicantReselected(applicationTarget, ports);
        await requests[0].succeed(snapshotFor('新製品'));

        expect(state.record.values.project).toBeNull();
        expect(state.record.values.department).toEqual(picked(3));
        expect(state.record.values.name).toBe('直した名前');
        expect(lastView()).toEqual({ busy: false, confirm: null, cleared: [] });
    });

    it('申請者を変えたら、前の申請者で読んだ値の確かめも消え、反映しても何も写らない', async () => {
        const typed = recordOf({ project: picked(9), name: '手で入れた名前' });
        const { state, requests, choose, applicationTarget, ports, lastView } =
            flowSetup(typed);

        choose(picked(5));
        await requests[0].succeed(snapshotFor('新製品'));
        expect(lastView()?.confirm).not.toBeNull();

        applicantChanged(applicationTarget, ports, 11, () => undefined);
        reflectApplicationTarget(applicationTarget, ports);

        expect(lastView()?.confirm).toBeNull();
        expect(state.record.values.name).toBe('手で入れた名前');
    });
});
