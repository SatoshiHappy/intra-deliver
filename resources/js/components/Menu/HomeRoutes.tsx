import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { HomeActionButton } from '@/components/Menu/HomeActionButton';
import { HomePanel } from '@/components/Menu/HomePanel';
import { cn } from '@/lib/utils';
import type { HomeRouteStep, HomeRoutesSection } from '@/types';

/** 状態ごとの点・ステップ名・前の線の色(docs の 設計/メニュー/ホーム.md 6.3 の「ステップの点と線」。省略 = 到達していないと同じ色で縁を破線 = ルートの深さ/認可と一覧.md 2 節の H3) */
const STEP_CLASSES: Record<
    HomeRouteStep['state'],
    { item: string; dot: string }
> = {
    done: {
        item: 'text-muted-foreground before:bg-primary',
        dot: 'border-primary bg-primary text-primary-foreground',
    },
    current: {
        item: 'font-medium text-foreground before:bg-primary',
        dot: 'border-primary bg-secondary',
    },
    returned: {
        item: 'font-medium text-status-returned-foreground before:bg-status-returned-foreground',
        dot: 'border-status-returned-foreground bg-status-returned',
    },
    upcoming: {
        item: 'text-muted-foreground before:bg-border',
        dot: 'border-input bg-card',
    },
    skipped: {
        item: 'text-muted-foreground before:bg-border',
        dot: 'border-dashed border-input bg-card',
    },
};

/**
 * ステップの点と線(ol。読み上げの名前「ステップ」)。状態は点の見た目と読み上げ用の `(<stateLabel>)` で伝え、色だけにしない。
 * 現在と戻りは aria-current="step"。
 */
function Steps({ steps }: { steps: HomeRouteStep[] }) {
    return (
        <ol aria-label="ステップ" className="mt-3 flex">
            {steps.map((step) => (
                <li
                    key={step.code}
                    aria-current={
                        step.state === 'current' || step.state === 'returned'
                            ? 'step'
                            : undefined
                    }
                    className={cn(
                        "relative grid min-w-0 flex-[0_1_72px] justify-items-center gap-1.5 text-center text-xs wrap-anywhere before:absolute before:top-[7px] before:right-1/2 before:-left-1/2 before:h-0.5 before:content-[''] first:before:hidden",
                        STEP_CLASSES[step.state].item,
                    )}
                >
                    <span
                        className={cn(
                            'relative z-10 grid size-4 place-items-center rounded-full border-2',
                            STEP_CLASSES[step.state].dot,
                        )}
                    >
                        {step.state === 'done' && (
                            <Check className="size-2.5 stroke-3" />
                        )}
                    </span>
                    {step.label}
                    <span className="sr-only">({step.stateLabel})</span>
                </li>
            ))}
        </ol>
    );
}

/**
 * 経路の節(docs の 設計/メニュー/ホーム.md 6.3)。項目を横線で区切り、題(リンク)・note・右上の操作(あれば)・ステップの点と線を出す。
 * 0 件なら emptyText。
 */
export function HomeRoutes({ section }: { section: HomeRoutesSection }) {
    return (
        <HomePanel
            code={section.code}
            heading={section.heading}
            more={section.more}
        >
            {section.items.length === 0 ? (
                <p className="py-7 text-center text-sm text-muted-foreground">
                    {section.emptyText}
                </p>
            ) : (
                <ul>
                    {section.items.map((item) => (
                        <li
                            key={item.code}
                            className="border-b px-0.5 py-3.5 first:pt-0.5 last:border-b-0 last:pb-0.5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 wrap-anywhere">
                                    <Link
                                        href={item.url}
                                        className="text-sm font-bold hover:text-primary"
                                    >
                                        {item.title}
                                    </Link>
                                    <p className="mt-px text-sm text-muted-foreground">
                                        {item.note}
                                    </p>
                                </div>
                                {item.action !== null && (
                                    <HomeActionButton
                                        action={item.action}
                                        ownerName={item.title}
                                        variant="outline"
                                        size="sm"
                                    />
                                )}
                            </div>
                            <Steps steps={item.steps} />
                        </li>
                    ))}
                </ul>
            )}
        </HomePanel>
    );
}
