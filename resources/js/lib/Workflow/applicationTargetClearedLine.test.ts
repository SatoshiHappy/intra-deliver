import { describe, expect, it } from 'vite-plus/test';
import { confirmOf, openingOf } from './applicationTarget';
import {
    APPLICATION_TARGET_FIELD,
    flowSetup,
    markedOf,
    opened,
    picked,
    planModule,
    recordOf,
    reference,
    snapshotOf,
} from './applicationTarget.testing';
import {
    cancelApplicationTarget,
    reflectApplicationTarget,
} from './applicationTargetFlow';
import type { WorkflowApplyProps } from '@/types';

// 申請対象の値の写しで空にした項目の 1 行(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節・申請対象からの申請.md の 10.1):
// 写すたびに置き換え(空なら消す)、確かめをやめた・取れなかった・申請対象を空にしたときは変えない。

const clearedSnapshot = (name: string, cleared: string[]) =>
    snapshotOf({ project: reference(5, 'P5'), name }, {}, cleared);

describe('写したときの 1 行', () => {
    it('開いた時に写すと、写しの値の名前が 1 行になる(確かめるときは反映するまで出さない)', () => {
        const props = {
            mode: 'new',
            module: planModule,
            targetField: APPLICATION_TARGET_FIELD,
            targetValues: clearedSnapshot('計画', ['担当者']),
            targetEntry: { module: 'project', record: 5 },
        } as WorkflowApplyProps;
        const typed = recordOf({ project: picked(5), name: '旧名' });

        expect(markedOf(openingOf(props, recordOf()).cleared)).toEqual([
            '担当者',
        ]);
        expect(
            openingOf({ ...props, mode: 'record', targetEntry: null }, typed)
                .cleared,
        ).toEqual([]);
    });

    it('写すたびに置き換え、空にした項目の無い写しで消える', async () => {
        const { requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));
        await requests[0].succeed(clearedSnapshot('', ['担当者', '組織']));
        expect(markedOf(lastView()?.cleared)).toEqual(['担当者', '組織']);

        choose(picked(6));
        await requests[1].succeed(clearedSnapshot('', []));
        expect(markedOf(lastView()?.cleared)).toEqual([]);
    });
});

describe('写したときの 1 行(確かめ・失敗)', () => {
    it('確かめで反映すると置き換わり、キャンセルでは変わらない', async () => {
        const typed = recordOf({ project: picked(9), name: '手で入れた名前' });
        const { requests, choose, applicationTarget, ports, lastView } =
            flowSetup(typed);

        choose(picked(5));
        await requests[0].succeed(clearedSnapshot('新製品', ['担当者']));
        expect(markedOf(lastView()?.cleared)).toEqual([]);
        reflectApplicationTarget(applicationTarget, ports);
        expect(markedOf(lastView()?.cleared)).toEqual(['担当者']);

        choose(picked(6));
        await requests[1].succeed(clearedSnapshot('別の名前', ['組織']));
        cancelApplicationTarget(applicationTarget, ports);
        expect(markedOf(lastView()?.cleared)).toEqual(['担当者']);
    });

    it('取れなかったとき・申請対象を空にしたときは変わらない', async () => {
        const { requests, choose, lastView } = flowSetup(opened);

        choose(picked(5));
        await requests[0].succeed(clearedSnapshot('', ['担当者']));
        choose(picked(6));
        await requests[1].fail('unavailable');
        choose(null);

        expect(markedOf(lastView()?.cleared)).toEqual(['担当者']);
    });
});

describe('空にした写す値と入力済みの値(申請対象からの申請.md の 10.2)', () => {
    it('写す時の確かめで空にした値(空)が入力済みの値と違えば確かめに出る', () => {
        const typed = recordOf({
            project: picked(9),
            department: picked(3, '総務部'),
        });
        const snapshot = snapshotOf(
            { project: reference(5, 'P5'), department: null },
            {},
            ['department'],
        );

        const confirm = confirmOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            typed,
            snapshot,
            picked(5),
        );

        expect(confirm?.changes.map((change) => change.field.code)).toEqual([
            'department',
        ]);
    });
});
