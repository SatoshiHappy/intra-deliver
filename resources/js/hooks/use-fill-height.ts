import { useEffect, useRef, useState } from 'react';
import { fillHeightOf } from '@/lib/scrollEdges';

/**
 * 要素の高さを、要素の上端から画面の下端までにする(下の余白 bottomGap を引き、minHeight より低くしない)。画面の大きさと
 * ページの中身の大きさ(上の見出しの折り返しなど)が変わったら測り直す。測る前は null(高さを与えない)。
 */
export function useFillHeight(bottomGap: number, minHeight: number) {
    const ref = useRef<HTMLDivElement>(null);
    const [height, setHeight] = useState<number | null>(null);

    useEffect(() => {
        const measure = () => {
            const element = ref.current;

            if (element !== null) {
                const top =
                    element.getBoundingClientRect().top + window.scrollY;
                setHeight(
                    fillHeightOf(window.innerHeight, top, bottomGap, minHeight),
                );
            }
        };
        const observer = new ResizeObserver(measure);
        observer.observe(document.body);
        window.addEventListener('resize', measure);
        measure();

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, [bottomGap, minHeight]);

    return { ref, height };
}
