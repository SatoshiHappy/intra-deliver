import { ControlButton } from '@xyflow/react';
import { Expand, Shrink } from 'lucide-react';
import type { DiagramFullscreen } from '@/hooks/Workflow/use-diagram-fullscreen';

/**
 * 「広げて表示」のボタン(拡大・縮小・全体を表示の並びの最後)。全画面の間は「元の大きさで表示」になる(Esc でも戻る)。
 * React Flow の部品の svg は塗りで描くので、線の絵の lucide は塗りを消す。
 */
export function FullscreenButton({
    fullscreen,
}: {
    fullscreen: DiagramFullscreen;
}) {
    if (!fullscreen.isEnabled) {
        return null;
    }

    const label = fullscreen.isFullscreen ? '元の大きさで表示' : '広げて表示';
    const Icon = fullscreen.isFullscreen ? Shrink : Expand;

    return (
        <ControlButton
            onClick={fullscreen.toggle}
            aria-label={label}
            title={label}
        >
            <Icon style={{ fill: 'none' }} />
        </ControlButton>
    );
}
