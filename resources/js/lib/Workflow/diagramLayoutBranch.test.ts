import { describe, expect, it } from 'vite-plus/test';
import { layoutDiagram } from './diagramLayout';
import {
    boxOf,
    branchRoute,
    edge,
    edgeOf,
    node,
} from './diagramLayout.testing';

describe('layoutDiagram: 図の側で足す箱と札', () => {
    it('分岐開始から分岐終了へ直接の辺に「何もしない」の箱を足し、同じ段の行き先と同じ高さ・大きさにする', () => {
        const layout = branchRoute({ manager: null, direct: null });
        const noop = boxOf(layout, '2-4-noop');
        const manager = boxOf(layout, '3');

        expect(noop.type).toBe('noop');
        expect(noop.data).toEqual({ taken: null });
        expect([noop.y, noop.width, noop.height]).toEqual([
            manager.y,
            manager.width,
            manager.height,
        ]);
        expect(layout.edges.map((one) => one.id)).toContain('2-2-4-noop');
        expect(layout.edges.map((one) => one.id)).toContain('2-4-noop-4');
        expect(layout.edges.map((one) => one.id)).not.toContain('2-4');
        expect(edgeOf(layout, '2-2-4-noop').text).toBe('予算 300 万円未満');
        expect(edgeOf(layout, '2-4-noop-4').text).toBeNull();
    });

    it('縦配置の枠の下に「同期終了」の札を足し、枠から出る辺は札から出す', () => {
        const layout = branchRoute({ manager: null, direct: null });
        const joint = boxOf(layout, '5-joint');
        const frame = boxOf(layout, '5');
        const ids = layout.edges.map((one) => one.id);

        expect([joint.type, joint.data]).toEqual([
            'joint',
            { label: '同期終了', tone: 'plain' },
        ]);
        expect(joint.y).toBeGreaterThan(frame.y + frame.height);
        expect(ids).toEqual(expect.arrayContaining(['5-5-joint', '5-joint-6']));
        expect(ids).not.toContain('5-6');
    });

    it('案件の図: 通らなかった枝の箱は taken が偽で、前後の辺は点線。通った枝の箱は taken が真で実線', () => {
        const layout = branchRoute({ manager: true, direct: false });
        const passed = branchRoute({ manager: false, direct: true });

        expect(boxOf(layout, '2-4-noop').data).toEqual({ taken: false });
        expect(
            ['2-2-4-noop', '2-4-noop-4', '2-3'].map(
                (id) => edgeOf(layout, id).dashed,
            ),
        ).toEqual([true, true, false]);
        expect(boxOf(passed, '2-4-noop').data).toEqual({ taken: true });
        expect(edgeOf(passed, '2-2-4-noop').dashed).toBe(false);
    });
});

describe('layoutDiagram: ひし形の角と枝の札', () => {
    it('分岐開始の左右の角から出て、分岐終了の左右の角へ入る', () => {
        const layout = branchRoute({ manager: null, direct: null });
        const start = boxOf(layout, '2');
        const end = boxOf(layout, '4');
        const startY = start.y + start.height / 2;

        expect(edgeOf(layout, '2-3').path).toMatch(
            new RegExp(`^M ${start.x} ${startY} H `),
        );
        expect(edgeOf(layout, '2-2-4-noop').path).toMatch(
            new RegExp(`^M ${start.x + start.width} ${startY} H `),
        );
        expect(edgeOf(layout, '3-4').path).toMatch(
            new RegExp(` V ${end.y + end.height / 2} H ${end.x}$`),
        );
        expect(edgeOf(layout, '2-4-noop-4').path).toMatch(
            new RegExp(` H ${end.x + end.width}$`),
        );
    });

    it('枝の札は行き先の箱のすぐ上に、どの枝も同じ高さで置く', () => {
        const layout = branchRoute({ manager: null, direct: null });
        const manager = boxOf(layout, '3');
        const noop = boxOf(layout, '2-4-noop');
        const left = edgeOf(layout, '2-3');
        const right = edgeOf(layout, '2-2-4-noop');

        expect(left.labelY).toBe(right.labelY);
        expect(left.labelY).toBeLessThan(manager.y);
        expect(left.labelY).toBeGreaterThan(manager.y - 40);
        expect([left.labelX, right.labelX]).toEqual([
            manager.x + manager.width / 2,
            noop.x + noop.width / 2,
        ]);
        expect([left.text, left.conditionText]).toEqual([
            '予算 300 万円以上',
            '予算(万円) ≧ 300',
        ]);
    });
});

describe('layoutDiagram: 3 本以上の枝', () => {
    it('3 本以上の枝は、外側の 2 本が左右の角、間の枝が下の角から出る', () => {
        const layout = layoutDiagram(
            [
                node(1, 'branch_start'),
                node(2, 'approve'),
                node(3, 'approve'),
                node(4, 'approve'),
                node(5, 'branch_end'),
            ],
            [2, 3, 4].flatMap((to) => [
                edge(1, to, { label: `枝${to}`, conditionText: `条件${to}` }),
                edge(to, 5),
            ]),
        );
        const start = boxOf(layout, '1');
        const end = boxOf(layout, '5');
        const middle = [2, 3, 4].find(
            (id) =>
                !edgeOf(layout, `1-${id}`).path.startsWith(`M ${start.x} `) &&
                !edgeOf(layout, `1-${id}`).path.startsWith(
                    `M ${start.x + start.width} `,
                ),
        );

        expect(middle).toBeDefined();
        expect(edgeOf(layout, `1-${middle}`).path).toMatch(
            new RegExp(
                `^M ${start.x + start.width / 2} ${start.y + start.height} `,
            ),
        );
        expect(edgeOf(layout, `${middle}-5`).path).toMatch(
            new RegExp(` V ${end.y}$`),
        );
    });
});
