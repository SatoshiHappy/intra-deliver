import { describe, expect, it, vi } from 'vite-plus/test';
import { filesOperationsOf } from './use-apply-attachment';

const a = new File(['a'], 'a.txt');
const b = new File(['b'], 'b.txt');
const c = new File(['c'], 'c.txt');

/** 並びの口の偽物(受けた更新関数を自分の今の並びに当てて持ち直す) */
function fakePort(initial: File[]) {
    const state = { files: initial };
    const port = {
        setFiles: vi.fn((update: (current: File[]) => File[]) => {
            state.files = update(state.files);
        }),
        onFilesChanged: vi.fn(),
    };

    return { port, state, operations: filesOperationsOf(port) };
}

describe('選んだファイルの並びを変える操作', () => {
    it('追加は今の並びの後ろに足し、onFilesChanged を 1 回呼ぶ', () => {
        const { port, state, operations } = fakePort([a]);

        operations.add([b, c]);

        expect(state.files).toEqual([a, b, c]);
        expect(port.onFilesChanged).toHaveBeenCalledTimes(1);
    });

    it('何も選ばない追加は何もしない', () => {
        const { port, operations } = fakePort([a]);

        operations.add([]);

        expect(port.setFiles).not.toHaveBeenCalled();
        expect(port.onFilesChanged).not.toHaveBeenCalled();
    });
});

describe('削除と空に戻す操作', () => {
    it('削除はそのファイルを今の並びから除き、onFilesChanged を呼ぶ', () => {
        const { port, state, operations } = fakePort([a, b, c]);

        operations.remove(b);

        expect(state.files).toEqual([a, c]);
        expect(port.onFilesChanged).toHaveBeenCalledTimes(1);
    });

    it('空に戻す(送れた後)は、選んでいたかによらず並びを空にして onFilesChanged を呼ぶ', () => {
        const filled = fakePort([a, b]);
        const empty = fakePort([]);

        filled.operations.reset();
        empty.operations.reset();

        expect(filled.state.files).toEqual([]);
        expect(empty.state.files).toEqual([]);
        expect(filled.port.onFilesChanged).toHaveBeenCalledTimes(1);
        expect(empty.port.onFilesChanged).toHaveBeenCalledTimes(1);
    });
});

describe('再描画の前に重ねた操作', () => {
    it('追加の後の削除は、追加した後の並びから除く', () => {
        const { state, operations } = fakePort([a, b]);

        operations.add([c]);
        operations.remove(a);

        expect(state.files).toEqual([b, c]);
    });

    it('同じファイルの 2 回の削除(二度押し)は、ほかのファイルを消さない(2 回目は並びを変えないが onFilesChanged は呼ぶ)', () => {
        const { port, state, operations } = fakePort([a, b, c]);

        operations.remove(a);
        operations.remove(a);

        expect(state.files).toEqual([b, c]);
        expect(port.onFilesChanged).toHaveBeenCalledTimes(2);
    });

    it('追加した直後のファイルの削除(同じ描画の中)は、追加した後の並びから除く', () => {
        const { state, operations } = fakePort([a]);

        operations.add([c]);
        operations.remove(c);

        expect(state.files).toEqual([a]);
    });

    it('削除の後に reset すると空になる', () => {
        const { state, operations } = fakePort([a, b]);

        operations.remove(a);
        operations.reset();

        expect(state.files).toEqual([]);
    });
});
