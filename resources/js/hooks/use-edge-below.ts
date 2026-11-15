import { useEffect, useState } from 'react';

/**
 * 印の要素が画面に見えていないか(docs の 設計/画面の意匠/README.md の方針 14: 画面の下に固定した帯の影は、帯の下に中身が続くときだけ)。
 * 印を中身の下端に置き、見えていなければ(下端が画面の下にある = 帯の下に中身が続く)真。markRef は印の要素の ref(要素が後から出ても追う)。
 */
export function useEdgeBelow() {
    const [mark, setMark] = useState<HTMLElement | null>(null);
    const [isBelow, setIsBelow] = useState(false);

    useEffect(() => {
        if (mark === null) {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                setIsBelow(!entry.isIntersecting);
            }
        });
        observer.observe(mark);

        return () => observer.disconnect();
    }, [mark]);

    return { markRef: setMark, isBelow };
}
