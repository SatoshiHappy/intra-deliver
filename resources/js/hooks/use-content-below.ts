import { useCallback, useEffect, useRef, useState } from 'react';
import { hasContentBelow } from '@/lib/scrollEdges';

/**
 * スクロールする欄の下に、まだ中身が続くか(docs の 設計/画面の意匠/README.md の方針 14 の帯の影)。scrollRef = スクロールする要素、
 * contentRef = その中の中身(大きさが変わったら判定し直す)。スクロール・欄と中身の大きさの変化で判定し直す。
 */
export function useContentBelow() {
    const scrollRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const [isBelow, setIsBelow] = useState(false);
    const measure = useCallback(() => {
        const element = scrollRef.current;

        if (element !== null) {
            setIsBelow(
                hasContentBelow(
                    element.scrollTop,
                    element.clientHeight,
                    element.scrollHeight,
                ),
            );
        }
    }, []);

    useEffect(() => {
        const element = scrollRef.current;

        if (element === null) {
            return;
        }

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        if (contentRef.current !== null) {
            observer.observe(contentRef.current);
        }

        element.addEventListener('scroll', measure, { passive: true });
        measure();

        return () => {
            observer.disconnect();
            element.removeEventListener('scroll', measure);
        };
    }, [measure]);

    return { scrollRef, contentRef, isBelow };
}
