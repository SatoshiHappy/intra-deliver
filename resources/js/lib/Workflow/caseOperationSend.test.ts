import { describe, expect, it } from 'vite-plus/test';
import {
    kobayashi,
    nodeAction,
    panel,
    reexpandNode,
    source,
    takahashi,
} from './caseOperation.testing';
import {
    canDecideReexpand,
    operationPayloadOf,
    operationTargetOf,
    releaseNodesOf,
    releaseTargetOf,
} from './caseOperations';
import type { OperationForm } from './caseOperations';

const operation = panel({
    releaseNodes: [
        { nodeId: 21, name: '課長' },
        { nodeId: 11, name: '部長' },
    ],
});

describe('送る内容と決定', () => {
    const data: OperationForm = {
        node_id: '11',
        users: [kobayashi, takahashi],
        comment: '長期不在のため',
        direction: 'back',
        send_back_node_id: '2',
    };

    it('処理担当者の変更・指定の変更は選んだ人の id の並びを、再展開は人を送らない', () => {
        const base = { revision: 4, node_id: 11, comment: '長期不在のため' };
        const sent = (kind: 'retarget' | 'reexpand' | 'assign') =>
            operationPayloadOf(
                data,
                operationTargetOf(kind, source(panel({ assignNodes: [] }))),
            );

        expect(sent('retarget')).toEqual({ ...base, user_ids: [5, 3] });
        expect(sent('assign')).toEqual({ ...base, user_ids: [5, 3] });
        expect(sent('reexpand')).toEqual(base);
    });

    it('ステップの移動は戻るのときだけ戻り先を送り、進む・終了は null', () => {
        const target = operationTargetOf('move', source());
        const base = { revision: 4, node_id: 11, comment: '長期不在のため' };

        expect(operationPayloadOf(data, target)).toEqual({
            ...base,
            direction: 'back',
            send_back_node_id: 2,
        });
        expect(
            operationPayloadOf({ ...data, direction: 'forward' }, target),
        ).toEqual({ ...base, direction: 'forward', send_back_node_id: null });
        expect(
            operationPayloadOf({ ...data, direction: 'finish' }, target),
        ).toEqual({ ...base, direction: 'finish', send_back_node_id: null });
    });

    it('再展開の決定は展開後が 1 人以上のときだけ', () => {
        expect(canDecideReexpand(reexpandNode({ expanded: [] }))).toBe(false);
        expect(canDecideReexpand(reexpandNode())).toBe(true);
        expect(canDecideReexpand(undefined)).toBe(false);
    });
});

describe('保留解除のダイアログの対象', () => {
    it('面の releaseNodes の並びで、自分の処理対象の行はその行(処理担当者の選択肢つき)、ほかは処理者を自分にする', () => {
        const self = {
            id: 9,
            name: '吉田 達也',
            isSelf: true,
            canAttach: false,
        };
        const rows = [
            nodeAction({
                nodeId: 21,
                name: '課長',
                release: true,
                principals: { release: [self] },
            }),
        ];
        const nodes = releaseNodesOf(operation, rows);

        expect(nodes.map((node) => [node.id, node.name])).toEqual([
            [21, '課長'],
            [11, '部長'],
        ]);
        expect(nodes[0].principals).toEqual([self]);
        expect(nodes[1].principals).toEqual([]);
        expect(nodes[1].fixedPrincipalId).toBeNull();
    });

    it('自分の行が無い管理者でも、releaseNodes のステップを選べる(保留解除の行が偽の行は使わない)', () => {
        const rows = [nodeAction({ nodeId: 21, release: false })];

        expect(releaseNodesOf(operation, rows).map((node) => node.id)).toEqual([
            21, 11,
        ]);
        expect(releaseNodesOf(panel(), [])).toEqual([]);
    });
});

describe('保留解除の対象の組み立て', () => {
    it('対象は処理のダイアログの release で、自分として送る', () => {
        const target = releaseTargetOf({
            ...source(operation),
            caseName: '新製品の発売計画',
            nodeActions: [],
        });

        expect(target.operation).toBe('release');
        expect(target.fixedPrincipalId).toBeNull();
        expect(target.revision).toBe(4);
        expect(target.nodes.map((node) => node.id)).toEqual([21, 11]);
    });
});
