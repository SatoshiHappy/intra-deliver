import { describe, expect, it } from 'vite-plus/test';
import { layoutDiagram } from './diagramLayout';
import type { DiagramInputNode } from './diagramTypes';

function node(
    id: number,
    kind: DiagramInputNode['kind'],
    overrides: Partial<DiagramInputNode> = {},
): DiagramInputNode {
    return {
        id,
        code: `n${id}`,
        name: `ノード${id}`,
        kind,
        parentId: null,
        sequence: null,
        subtitle: null,
        state: null,
        people: [],
        placeholders: [],
        consensus: null,
        ...overrides,
    };
}

const PLACEHOLDERS = [
    { label: '承認 1', optional: false },
    { label: '承認 2', optional: false },
    { label: '承認 3 から 5', optional: true },
];

describe('layoutDiagram: 定義の図の縦配置の枠', () => {
    it('定義の図の枠は、仮の札を枠の子として並べ、枠の大きさに数える', () => {
        const nodes = [
            node(1, 'apply'),
            node(2, 'parallel', { placeholders: PLACEHOLDERS }),
        ];
        const layout = layoutDiagram(nodes, [
            { from: 1, to: 2, label: null, taken: null },
        ]);
        const frame = layout.nodes.find((one) => one.id === '2');
        const slots = layout.nodes.filter((one) => one.type === 'placeholder');

        expect(frame?.type).toBe('frame');
        expect(frame?.data).toMatchObject({ childCount: 3 });
        expect(slots.map((one) => [one.id, one.parentId])).toEqual([
            ['2-slot-0', '2'],
            ['2-slot-1', '2'],
            ['2-slot-2', '2'],
        ]);
        expect(slots.map((one) => one.data)).toEqual(PLACEHOLDERS);
        // 縦配置は横一列(同じ高さで、左から右へ)
        expect(new Set(slots.map((one) => one.position.y)).size).toBe(1);
        const xs = slots.map((one) => one.position.x);

        expect(xs).toEqual(xs.toSorted((a, b) => a - b));
        expect(slots[0].position.x).toBeGreaterThanOrEqual(0);
        expect(slots[2].position.x + (slots[2].width ?? 0)).toBeLessThanOrEqual(
            frame?.width ?? 0,
        );
    });
});

describe('layoutDiagram: 定義の図の横配置の枠', () => {
    it('横配置の仮の札は縦一列に並べる', () => {
        const layout = layoutDiagram(
            [node(1, 'serial', { placeholders: PLACEHOLDERS })],
            [],
        );
        const slots = layout.nodes.filter((one) => one.type === 'placeholder');

        expect(new Set(slots.map((one) => one.position.x)).size).toBe(1);
        const ys = slots.map((one) => one.position.y);

        expect(ys).toEqual(ys.toSorted((a, b) => a - b));
    });
});

describe('layoutDiagram: 案件の図の枠', () => {
    it('案件の図の枠は、展開の行を子の箱(step)として並べ、仮の札は無い', () => {
        const nodes = [
            node(1, 'parallel'),
            node(2, 'approve', { parentId: 1, sequence: 1 }),
            node(3, 'approve', { parentId: 1, sequence: 2 }),
        ];
        const layout = layoutDiagram(nodes, []);

        expect(
            layout.nodes.filter((one) => one.type === 'placeholder'),
        ).toEqual([]);
        expect(
            layout.nodes
                .filter((one) => one.parentId === '1')
                .map((one) => [one.id, one.type]),
        ).toEqual([
            ['2', 'step'],
            ['3', 'step'],
        ]);
        expect(layout.nodes.find((one) => one.id === '1')?.data).toMatchObject({
            childCount: 2,
        });
    });

    it('子も仮の札も無い枠の子の数は 0', () => {
        const layout = layoutDiagram([node(1, 'parallel')], []);

        expect(layout.nodes.find((one) => one.id === '1')?.data).toMatchObject({
            childCount: 0,
        });
    });
});
