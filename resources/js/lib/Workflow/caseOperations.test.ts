import { describe, expect, it } from 'vite-plus/test';
import {
    assignNode,
    kobayashi,
    moveNode,
    panel,
    retargetNode,
    source,
    takahashi,
} from './caseOperation.testing';
import {
    initialOperationForm,
    movableDirectionsOf,
    nodeDefaultsOf,
    nodeIn,
    nodeSelectOf,
    operationTargetOf,
} from './caseOperations';

describe('対象', () => {
    it('種類ごとに、開いた時の面の値と選べるステップを写す', () => {
        const operation = panel({ assignNodes: [assignNode()] });
        const kinds = ['retarget', 'reexpand', 'move', 'assign'] as const;
        const nodes = [
            operation.retargetNodes,
            operation.reexpandNodes,
            operation.moveNodes,
            operation.assignNodes,
        ];

        kinds.forEach((kind, index) => {
            expect(operationTargetOf(kind, source(operation))).toEqual({
                kind,
                caseId: 7,
                caseName: '新製品の発売計画',
                revision: 4,
                commentMaxLength: 4000,
                nodes: nodes[index],
            });
        });
    });
});

describe('ステップの選択欄', () => {
    it('ステップの選択欄: 指定の変更は「ステップ」でいつも出し、ほかは 2 つ以上のときだけ「対象のステップ」', () => {
        const operation = panel({
            retargetNodes: [
                retargetNode({ nodeId: 31 }),
                retargetNode({ nodeId: 32, name: '経理' }),
            ],
            assignNodes: [assignNode()],
        });

        expect(
            nodeSelectOf(operationTargetOf('retarget', source(operation))),
        ).toEqual({
            label: '対象のステップ',
            choices: [
                { id: 31, name: '部長' },
                { id: 32, name: '経理' },
            ],
        });
        expect(
            nodeSelectOf(operationTargetOf('reexpand', source(operation))),
        ).toBeNull();
        expect(
            nodeSelectOf(operationTargetOf('assign', source(operation))),
        ).toEqual({
            label: 'ステップ',
            choices: [
                {
                    id: 31,
                    name: '縦配置「関係部署の合議」　まだ到達していません',
                },
            ],
        });
    });
});

describe('入力の初期値(処理担当者の変更)', () => {
    it('処理担当者の変更・再展開は先頭のステップを選び、人とコメントは空', () => {
        const target = operationTargetOf(
            'retarget',
            source(
                panel({
                    retargetNodes: [
                        retargetNode({ nodeId: 31 }),
                        retargetNode({ nodeId: 32 }),
                    ],
                }),
            ),
        );
        const nodes: { nodeId: number }[] = target.nodes;

        expect(initialOperationForm(target)).toMatchObject({
            node_id: '31',
            users: [],
            comment: '',
        });
        expect(nodeIn(nodes, '32')?.nodeId).toBe(32);
        expect(nodeIn(nodes, '99')).toBeUndefined();
    });
});

describe('入力の初期値(ステップの移動)', () => {
    it('ステップの移動は戻る・既定の戻り先(最後の候補)から始め、戻れなければ進む、進めなければ終了', () => {
        const form = (node: ReturnType<typeof moveNode>) =>
            initialOperationForm(
                operationTargetOf('move', source(panel({ moveNodes: [node] }))),
            );

        expect(form(moveNode())).toMatchObject({
            direction: 'back',
            send_back_node_id: '2',
        });
        expect(
            form(moveNode({ backNodes: [], defaultBackNodeId: null })),
        ).toMatchObject({ direction: 'forward', send_back_node_id: '' });
        expect(
            form(
                moveNode({
                    backNodes: [],
                    defaultBackNodeId: null,
                    canForward: false,
                }),
            ),
        ).toMatchObject({ direction: 'finish' });
    });
});

describe('移動の種類', () => {
    it('移動の種類のうち選べるもの: 戻る = 候補がある、進む = 進められる、終了 = いつも', () => {
        expect(movableDirectionsOf(moveNode())).toEqual({
            back: true,
            forward: true,
            finish: true,
        });
        expect(
            movableDirectionsOf(moveNode({ backNodes: [], canForward: false })),
        ).toEqual({ back: false, forward: false, finish: true });
    });
});

describe('入力の初期値(指定の変更)', () => {
    it('指定の変更は今の指定から始め、ステップを替えると替えたステップの今の指定に入れ直す', () => {
        const target = operationTargetOf(
            'assign',
            source(
                panel({
                    assignNodes: [
                        assignNode(),
                        assignNode({ nodeId: 32, assigned: [] }),
                    ],
                }),
            ),
        );

        expect(initialOperationForm(target).users).toEqual([
            takahashi,
            kobayashi,
        ]);
        expect(nodeDefaultsOf(target, '32').users).toEqual([]);
        expect(nodeDefaultsOf(target, '31').users).toEqual([
            takahashi,
            kobayashi,
        ]);
    });
});
