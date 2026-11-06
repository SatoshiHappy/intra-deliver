import { describe, expect, it } from 'vite-plus/test';
import { frameHeaderWidth, subtitleText } from './diagramFrames';
import { layoutDiagram } from './diagramLayout';
import { boxOf, edge, edgeOf, node } from './diagramLayout.testing';

/**
 * 図の短い文の区切りと確認の札の配置(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.3 節、
 * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)。
 */

describe('subtitleText(短い文の区切りをつないだ文)', () => {
    it('強調の区切りも字としてつなぐ(幅の見積もりと title に使う)', () => {
        expect(
            subtitleText([
                { text: '申請者', emphasis: true },
                'の所属組織の',
                { text: '課長', emphasis: true },
                '　期限 3 日',
            ]),
        ).toBe('申請者の所属組織の課長　期限 3 日');
    });

    it('枠の見出しの幅は、つないだ文の字数で見積もる(期限を足すと広がる)', () => {
        const without = node(5, 'parallel', {
            name: '関係部署の合議',
            subtitle: ['課長が承認者を選択'],
        });
        const withDeadline = {
            ...without,
            subtitle: ['課長が承認者を選択　期限 3 日'],
        };

        expect(frameHeaderWidth(withDeadline) - frameHeaderWidth(without)).toBe(
            '　期限 3 日'.length * 12,
        );
    });
});

describe('layoutDiagram: 確認の札', () => {
    it('確認する人がいる箱は札の分(間 6 + 札 22)だけ高く、次の箱への辺は札の下から出る', () => {
        const layout = layoutDiagram(
            [
                node(1, 'apply'),
                node(2, 'approve', { confirmBadge: '確認：山田 恵' }),
                node(3, 'approve'),
            ],
            [edge(1, 2), edge(2, 3)],
        );
        const confirmed = boxOf(layout, '2');

        expect(boxOf(layout, '1').height).toBe(84);
        expect(confirmed.height).toBe(84 + 6 + 22);
        expect(edgeOf(layout, '2-3').path).toContain(
            `${confirmed.y + confirmed.height}`,
        );
    });
});
