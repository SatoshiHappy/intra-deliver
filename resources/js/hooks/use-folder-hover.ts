import { useEffect, useRef, useState } from 'react';
import type { PointerEvent } from 'react';

/** 行に乗せてからパネルを開くまで(ms) */
const OPEN_DELAY = 300;

/** 行とパネルの両方から外れてから閉じるまで(ms。行からパネルへ動く間は閉じない) */
const CLOSE_DELAY = 200;

/**
 * サイドバーの閉じたフォルダのマウスオーバーのパネルの開閉(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節の「フォルダのマウスオーバー」)。
 * 行の pointerenter で pointerType が mouse のときだけ 300ms で開き(タッチ・ペン・フォーカスでは開かない)、行とパネルの両方から外れて 200ms で閉じる。
 * enabled が偽の間(広げたサイドバーでない・フォルダが開いている・畳んだ帯のどれかの一覧が開いている)は開かない。
 * close はすぐ閉じる(フォルダを押して開いたとき・パネルのリンクを押したとき)。
 * rowProps を行に、panelProps をパネルに付ける。
 */
export function useFolderHover(enabled: boolean) {
    const [isOpen, setIsOpen] = useState(false);
    const timer = useRef<number | undefined>(undefined);

    // 開けない間(フォルダを開いた・サイドバーの状態が変わった)に開いていたら閉じる(後で開けるようになったとき、乗せずに出ないように。
    // props に合わせて状態を直す React の作法 = 描画の中で自分の状態だけを変える)
    if (!enabled && isOpen) {
        setIsOpen(false);
    }

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const schedule = (open: boolean, delay: number) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setIsOpen(open), delay);
    };

    const enter = (event: PointerEvent<HTMLElement>) => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        if (isOpen) {
            window.clearTimeout(timer.current);
        } else if (enabled) {
            schedule(true, OPEN_DELAY);
        }
    };

    const leave = (event: PointerEvent<HTMLElement>) => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        if (isOpen) {
            schedule(false, CLOSE_DELAY);
        } else {
            window.clearTimeout(timer.current);
        }
    };

    const close = () => {
        window.clearTimeout(timer.current);
        setIsOpen(false);
    };

    const handlers = { onPointerEnter: enter, onPointerLeave: leave };

    return {
        open: enabled && isOpen,
        rowProps: handlers,
        panelProps: handlers,
        close,
    };
}

export type FolderHover = ReturnType<typeof useFolderHover>;
