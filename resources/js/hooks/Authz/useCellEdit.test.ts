import { describe, expect, it } from 'vite-plus/test';
import { cellPresserOf } from './useCellEdit';
import type { CellPut } from './useCellEdit';

// 認可設定のセルの保存(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.1): 保存の間はどのセルを押しても送らず、
// 前の保存の応答(誤りのトーストを含む)を捨てない。終われば次の押下を送る。

type Sent = {
    payload: Parameters<CellPut>[0];
    handlers: Parameters<CellPut>[1];
};

function setup() {
    const sent: Sent[] = [];
    const savings: boolean[] = [];
    const press = cellPresserOf(
        (payload, handlers) => sent.push({ payload, handlers }),
        (saving) => savings.push(saving),
    );

    return { sent, savings, press };
}

const payloadOf = (to: string) => ({ to }) as unknown as Parameters<CellPut>[0];

describe('保存の間の押下', () => {
    it('保存の間は、同じセルも別のセルも送らない', () => {
        const { sent, press } = setup();

        press(payloadOf('permit'));
        sent[0].handlers.onStart();
        press(payloadOf('permit'));
        press(payloadOf('deny'));

        expect(sent).toHaveLength(1);
    });

    it('始まる前の 2 つ目の押下も送らない(saving の描画を待たない)', () => {
        const { sent, press } = setup();

        press(payloadOf('permit'));
        press(payloadOf('deny'));

        expect(sent).toHaveLength(1);
    });

    it('終われば(失敗でも)次の押下を送り、保存の印は始まりで立ち終わりで下りる', () => {
        const { sent, savings, press } = setup();

        press(payloadOf('permit'));
        sent[0].handlers.onStart();
        sent[0].handlers.onFinish();
        press(payloadOf('deny'));

        expect(sent.map((item) => item.payload)).toEqual([
            payloadOf('permit'),
            payloadOf('deny'),
        ]);
        expect(savings).toEqual([true, false]);
    });
});
