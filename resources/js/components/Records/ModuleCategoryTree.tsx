import { closestCenter, useDroppable } from '@dnd-kit/core';
import type {
    CollisionDetection,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import { arrayMove, hasSortableData } from '@dnd-kit/sortable';
import { GripVertical } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MenuIcon } from '@/components/MenuIcon';
import {
    moveWithin,
    SortableArea,
    SortableRows,
    SortHandle,
    useSortableRow,
} from '@/components/Records/SortableList';
import { Badge } from '@/components/ui/badge';
import {
    categoryCountLabel,
    dropTarget,
    headingId,
    membershipOf,
    MODULE_CATEGORY_PROPS,
    moduleListId,
    moduleRowId,
    MOVE_NETWORK_ERROR_MESSAGE,
    parentOf,
    parseTreeNodeId,
    treeAfterDrop,
} from '@/lib/Records/moduleCategories';
import type { ModuleParent } from '@/lib/Records/moduleCategories';
import { putOrder } from '@/lib/Records/sortOrder';
import { cn } from '@/lib/utils';
import { reorder, reorderModules } from '@/routes/records/admin/categories';
import {
    categories as updateModuleCategories,
    reorder as reorderUncategorized,
} from '@/routes/records/admin/modules';
import type {
    ModuleCategoryRow,
    RecordModuleSummary,
    UncategorizedModule,
} from '@/types';

type Order = {
    categories: ModuleCategoryRow[];
    uncategorized: UncategorizedModule[];
};

type Props = Order & {
    /** 選んだ分類の ID(null = 選んでいない) */
    selectedId: string | null;
    onSelect: (id: string) => void;
};

/** 落とすと所属が変わる親の囲み(破線の枠 = フォーカスの実線の輪と見分ける。地は選んだ行の bg-secondary より薄い = 4.4) */
const DROP_PARENT_CLASS =
    'outline-dashed outline-2 outline-primary outline-offset-2 bg-secondary/40';

/**
 * Ctrl(または ⌘)を押しているか(4.4 のコピー)。ページにいる間、window の keydown・keyup・pointermove の修飾キーで持ち、フォーカスが離れたら
 * 押していない扱いにする。ref = 落とした瞬間に読む値(描画を待たない)、isPressed = 写しの札とカーソルを描く値。
 */
function useCopyModifier() {
    const ref = useRef(false);
    const [isPressed, setPressed] = useState(false);

    useEffect(() => {
        const set = (next: boolean) => {
            if (ref.current !== next) {
                ref.current = next;
                setPressed(next);
            }
        };
        const fromEvent = (event: KeyboardEvent | PointerEvent) =>
            set(event.ctrlKey || event.metaKey);
        const release = () => set(false);
        window.addEventListener('keydown', fromEvent);
        window.addEventListener('keyup', fromEvent);
        window.addEventListener('pointermove', fromEvent);
        window.addEventListener('blur', release);

        return () => {
            window.removeEventListener('keydown', fromEvent);
            window.removeEventListener('keyup', fromEvent);
            window.removeEventListener('pointermove', fromEvent);
            window.removeEventListener('blur', release);
        };
    }, []);

    return { ref, isPressed };
}

/**
 * 木の当たりの判定(4.4 の「つかめる行」): つかんだ行の種類で候補を絞る。分類の行 = 分類の行どうし(中心の近さ)。業務モジュールの行のキーボード
 * (ポインタの座標が無い)= 同じ並びの行だけ(見出しを含めない。中心の近さ)。業務モジュールの行のマウス = すべての親の見出しの当たりと、すべての
 * 親の中の業務モジュールの行を、候補を囲む矩形の横幅まで広げて、ポインタの高さにあるものを当たりにして覚える(字下げの上でも行に付いてくる)。
 * ポインタの高さに候補が無い(候補を囲む矩形の中の隙間)ときは直前の当たりを保ち(見えている落とし先のまま)、ポインタが候補を囲む矩形の外にある
 * ときは当たり無し(落としても何も起きない)= 当たりがほかの親へ跳ねない。親の全体の矩形は当たりにしない。
 * lastOver = マウスのドラッグの直前の当たり(木の部品がドラッグの開始・終わり・取り消しで null に戻す)。
 */
function treeCollisionDetection(lastOver: {
    current: UniqueIdentifier | null;
}): CollisionDetection {
    return (args) => {
        const active = parseTreeNodeId(String(args.active.id));
        const kindOf = (id: string) => parseTreeNodeId(id).kind;
        const listOf = (entry: (typeof args.droppableContainers)[number]) =>
            hasSortableData(entry)
                ? entry.data.current.sortable.containerId
                : undefined;

        if (active.kind === 'category') {
            return closestCenter({
                ...args,
                droppableContainers: args.droppableContainers.filter(
                    (entry) => kindOf(String(entry.id)) === 'category',
                ),
            });
        }

        const pointer = args.pointerCoordinates;

        if (pointer === null) {
            const list = moduleListId(
                active.kind === 'module' ? active.parent : null,
            );

            return closestCenter({
                ...args,
                droppableContainers: args.droppableContainers.filter(
                    (entry) => listOf(entry) === list,
                ),
            });
        }

        const candidates = args.droppableContainers.flatMap((entry) => {
            const rect = args.droppableRects.get(entry.id);

            return kindOf(String(entry.id)) === 'category' || rect === undefined
                ? []
                : [{ id: entry.id, rect }];
        });
        const isInside =
            candidates.length > 0 &&
            pointer.x >= Math.min(...candidates.map((c) => c.rect.left)) &&
            pointer.x <= Math.max(...candidates.map((c) => c.rect.right)) &&
            pointer.y >= Math.min(...candidates.map((c) => c.rect.top)) &&
            pointer.y <= Math.max(...candidates.map((c) => c.rect.bottom));

        if (!isInside) {
            lastOver.current = null;

            return [];
        }

        const hit = candidates.find(
            (c) => pointer.y >= c.rect.top && pointer.y <= c.rect.bottom,
        );

        if (hit !== undefined) {
            lastOver.current = hit.id;
        }

        return lastOver.current === null ? [] : [{ id: lastOver.current }];
    };
}

/**
 * 業務モジュールの行(取っ手と名前。選べない)。つかんでいる間は元の位置を薄く残す(写しがポインタに付いて動く)。
 */
function ModuleItem({
    parent,
    module,
}: {
    parent: ModuleParent;
    module: RecordModuleSummary;
}) {
    const { setNodeRef, style, handle } = useSortableRow(
        moduleRowId(parent, module.code),
    );

    return (
        <li
            ref={setNodeRef}
            style={style}
            className={cn(
                'flex items-center gap-1 rounded-md',
                handle.isDragging && 'opacity-40',
            )}
        >
            <SortHandle
                handle={handle}
                label={`${module.name}の並び順を変更`}
            />
            <span className="text-sm">{module.name}</span>
        </li>
    );
}

/**
 * 業務モジュールの並び(分類の中・「分類なし」の表示中)。children = 並びの後に続ける行(「分類なし」の非表示)。
 */
function ModuleList({
    parent,
    label,
    modules,
    disabled,
    children,
}: {
    parent: ModuleParent;
    label: string;
    modules: RecordModuleSummary[];
    disabled: boolean;
    children?: ReactNode;
}) {
    return (
        <SortableRows
            id={moduleListId(parent)}
            ids={modules.map((module) => moduleRowId(parent, module.code))}
            disabled={disabled}
        >
            <ul aria-label={label} className="flex flex-col">
                {modules.map((module) => (
                    <ModuleItem
                        key={module.code}
                        parent={parent}
                        module={module}
                    />
                ))}
                {children}
            </ul>
        </SortableRows>
    );
}

/**
 * 分類の行(取っ手・アイコン folder・名前・件数。押すと選ぶ。この 1 行が業務モジュールの行を落とす先の見出しの当たり)と、字下げした中の業務モジュールの行。
 * isDropParent = 落とすと所属が変わる親(行と中の行の全体を囲む)。
 */
function CategoryItem({
    category,
    isSelected,
    onSelect,
    disabled,
    isDropParent,
}: {
    category: ModuleCategoryRow;
    isSelected: boolean;
    onSelect: () => void;
    disabled: boolean;
    isDropParent: boolean;
}) {
    const { setNodeRef, style, handle } = useSortableRow(category.id);
    const heading = useDroppable({ id: headingId(category.id) });

    return (
        <li
            ref={setNodeRef}
            style={style}
            className={cn(
                'flex flex-col rounded-md',
                handle.isDragging && 'opacity-40',
                isDropParent && DROP_PARENT_CLASS,
            )}
        >
            <div ref={heading.setNodeRef} className="flex items-center gap-1">
                <SortHandle
                    handle={handle}
                    label={`${category.name}の並び順を変更`}
                />
                <button
                    type="button"
                    aria-pressed={isSelected}
                    onClick={onSelect}
                    className={cn(
                        'flex h-10 min-w-0 flex-1 items-center gap-2 rounded-md px-2 text-left text-sm hover:bg-accent hover:text-accent-foreground',
                        isSelected && 'bg-secondary text-secondary-foreground',
                    )}
                >
                    <MenuIcon name="folder" />
                    <span className="truncate font-medium">
                        {category.name}
                    </span>
                    <span className="ml-auto shrink-0 font-num text-muted-foreground tabular-nums">
                        {categoryCountLabel(category.modules.length)}
                    </span>
                </button>
            </div>
            {category.modules.length > 0 && (
                <div className="pl-9">
                    <ModuleList
                        parent={category.id}
                        label={`${category.name}の業務モジュール`}
                        modules={category.modules}
                        disabled={disabled}
                    />
                </div>
            )}
        </li>
    );
}

/**
 * 「分類なし」の見出し(題と説明。業務モジュールの行を落とす先の見出しの当たり)。
 */
function UncategorizedHeading() {
    const { setNodeRef } = useDroppable({ id: headingId(null) });

    return (
        <div ref={setNodeRef} className="flex flex-col gap-1">
            <h2 id="uncategorized-heading" className="text-sm font-medium">
                分類なし
            </h2>
            <p className="text-sm text-muted-foreground">
                表示中は「業務」の直下に表示されます。非表示はサイドバーに表示されません
            </p>
        </div>
    );
}

/**
 * つかんだ行の写し(ポインタに付いて動く)。isCopy = Ctrl を押している間の業務モジュールの行(札「コピー」とカーソル copy)。
 */
function DragCopy({
    name,
    isCategory,
    isCopy,
}: {
    name: string;
    isCategory: boolean;
    isCopy: boolean;
}) {
    return (
        <div
            className={cn(
                'flex h-10 items-center gap-2 rounded-md border bg-card px-2 text-sm shadow-md',
                isCopy ? 'cursor-copy' : 'cursor-grabbing',
            )}
        >
            <GripVertical className="size-4 text-muted-foreground" />
            {isCategory && <MenuIcon name="folder" />}
            <span className={cn(isCategory && 'font-medium')}>{name}</span>
            {isCopy && <Badge variant="secondary">コピー</Badge>}
        </div>
    );
}

/**
 * モジュール分類の木(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4): 分類の行と中の業務モジュールの行、末尾に固定の「分類なし」の区画
 * (表示中は取っ手と名前、続けて非表示は取っ手なしで札「非表示」と薄い字)。木の全体が 1 つの外枠で、並びごと(分類の並び・分類の中・「分類なし」の表示中)に
 * 並びを置く。取っ手のドラッグで:
 * - 分類は分類どうしで動き、records.admin.categories.reorder へ { ids }。
 * - 業務モジュールは同じ親の中で並べ替え(分類の中は records.admin.categories.reorder-modules、「分類なし」は records.admin.modules.reorder へ { codes }。
 *   自分の親の見出しに落としても何もしない)、別の親(その見出しか中の行)へ落とすと所属を変える(Ctrl なし = 移動、Ctrl あり = コピー。目標が今と同じなら
 *   送らない。records.admin.modules.categories へ { categories }。先の親の末尾に入る)。キーボード(Space → 矢印 → Space)は同じ親の中だけ。
 * 落とすと手元の並びを先に変え(親をまたぐときは目標から作る)、要求が終わったら(成否に依らず)props に戻る。送っている間は次のドラッグを受けない。
 * ドラッグの間は写しがポインタに付き、落とすと所属が変わる親を破線で囲む。業務モジュールの行をマウスで候補を囲む矩形の外(木の外)へ出して落とすと
 * 何もしない(当たり無し。{@link treeCollisionDetection})。
 */
export function ModuleCategoryTree({
    categories,
    uncategorized,
    selectedId,
    onSelect,
}: Props) {
    // 要求の間だけの手元の並び(null = props の並び)
    const [pending, setPending] = useState<Order | null>(null);
    // ドラッグの間の、つかんだ行・当たり・キーボードで始めたか
    const [drag, setDrag] = useState<{
        activeId: string;
        overId: string | null;
        isKeyboard: boolean;
    } | null>(null);
    const copy = useCopyModifier();
    // マウスのドラッグの直前の当たり(当たりの判定が覚え、ドラッグの開始・終わり・取り消しで忘れる)
    const lastOver = useRef<UniqueIdentifier | null>(null);
    const collisionDetection = useMemo(
        () => treeCollisionDetection(lastOver),
        [],
    );
    const shown = pending ?? { categories, uncategorized };
    const isSending = pending !== null;
    const visible = shown.uncategorized.filter((module) => module.isVisible);
    const hidden = shown.uncategorized.filter((module) => !module.isVisible);
    const categoryIds = shown.categories.map((category) => category.id);

    const modulesOf = (parent: ModuleParent): RecordModuleSummary[] =>
        parent === null
            ? visible
            : (shown.categories.find((category) => category.id === parent)
                  ?.modules ?? []);

    const moduleName = (code: string) =>
        [
            ...shown.categories.flatMap((category) => category.modules),
            ...shown.uncategorized,
        ].find((module) => module.code === code)?.name ?? '';

    const nameOf = (id: string) => {
        const node = parseTreeNodeId(id);

        if (node.kind === 'module') {
            return moduleName(node.code);
        }

        const categoryId =
            node.kind === 'category' ? node.categoryId : node.parent;

        return categoryId === null
            ? '分類なし'
            : (shown.categories.find((category) => category.id === categoryId)
                  ?.name ?? '');
    };

    /** 業務モジュールの行を別の親へ落としたときの目標の所属(送らないとき・業務モジュールの行でないときは null) */
    const targetOf = (activeId: string, overId: string, isCopy: boolean) => {
        const active = parseTreeNodeId(activeId);
        const to = parentOf(overId);

        if (
            active.kind !== 'module' ||
            to === undefined ||
            to === active.parent
        ) {
            return null;
        }

        const current = membershipOf(categories, active.code);
        const target = dropTarget(
            current,
            active.parent,
            to,
            isCopy,
            categoryIds,
        );

        return target === null
            ? null
            : { module: active.code, to, current, target };
    };

    const send = (
        next: Order,
        url: string,
        data: Record<string, string[]>,
        networkErrorMessage?: string,
    ) => {
        setPending(next);
        putOrder(
            url,
            data,
            MODULE_CATEGORY_PROPS,
            () => setPending(null),
            networkErrorMessage,
        );
    };

    const moveCategory = (from: number, to: number) => {
        const next = arrayMove(shown.categories, from, to);
        send({ ...shown, categories: next }, reorder().url, {
            ids: next.map((category) => category.id),
        });
    };

    const moveInParent = (parent: ModuleParent, from: number, to: number) => {
        const modules = arrayMove(modulesOf(parent), from, to);
        const codes = { codes: modules.map((module) => module.code) };

        if (parent === null) {
            send(
                {
                    ...shown,
                    uncategorized: [
                        ...modules.map((module) => ({
                            ...module,
                            isVisible: true,
                        })),
                        ...hidden,
                    ],
                },
                reorderUncategorized().url,
                codes,
            );

            return;
        }

        send(
            {
                ...shown,
                categories: shown.categories.map((category) =>
                    category.id === parent
                        ? { ...category, modules }
                        : category,
                ),
            },
            reorderModules(parent).url,
            codes,
        );
    };

    const start = ({ active, activatorEvent }: DragStartEvent) => {
        lastOver.current = null;
        setDrag({
            activeId: String(active.id),
            overId: null,
            isKeyboard: activatorEvent instanceof KeyboardEvent,
        });
    };

    const track = ({ over }: DragOverEvent) =>
        setDrag((current) =>
            current === null
                ? null
                : {
                      ...current,
                      overId: over === null ? null : String(over.id),
                  },
        );

    const drop = ({ active, over }: DragEndEvent) => {
        // 移動かコピーかは落とした瞬間の Ctrl で決める(キーボードのドラッグでは見ない)
        const isCopy = drag?.isKeyboard === false && copy.ref.current;
        lastOver.current = null;
        setDrag(null);

        if (isSending || over === null) {
            return;
        }

        const activeNode = parseTreeNodeId(String(active.id));

        if (activeNode.kind === 'category') {
            const move = moveWithin(categoryIds, active.id, over.id);

            if (move !== null) {
                moveCategory(...move);
            }

            return;
        }

        if (activeNode.kind !== 'module') {
            return;
        }

        if (parentOf(String(over.id)) === activeNode.parent) {
            // 同じ親 = 並べ替え(自分の親の見出しの上では並びに無いので何もしない)
            const move = moveWithin(
                modulesOf(activeNode.parent).map((module) =>
                    moduleRowId(activeNode.parent, module.code),
                ),
                active.id,
                over.id,
            );

            if (move !== null) {
                moveInParent(activeNode.parent, ...move);
            }

            return;
        }

        const change = targetOf(String(active.id), String(over.id), isCopy);

        if (change === null) {
            return;
        }

        send(
            treeAfterDrop(
                shown,
                { code: change.module, name: moduleName(change.module) },
                change.current,
                change.target,
            ),
            updateModuleCategories(change.module).url,
            { categories: change.target },
            MOVE_NETWORK_ERROR_MESSAGE,
        );
    };

    const isCopyShown = drag !== null && !drag.isKeyboard && copy.isPressed;
    // 落とすと所属が変わる親(囲む。undefined = 無い)
    const dropParent =
        drag === null || drag.overId === null || drag.isKeyboard
            ? undefined
            : targetOf(drag.activeId, drag.overId, copy.isPressed)?.to;
    const activeNode = drag === null ? null : parseTreeNodeId(drag.activeId);

    return (
        <SortableArea
            id="module-category-tree"
            nameOf={nameOf}
            collisionDetection={collisionDetection}
            onDragStart={start}
            onDragOver={track}
            onDragEnd={drop}
            onDragCancel={() => {
                lastOver.current = null;
                setDrag(null);
            }}
            overlay={
                drag !== null && activeNode !== null ? (
                    <DragCopy
                        name={nameOf(drag.activeId)}
                        isCategory={activeNode.kind === 'category'}
                        isCopy={activeNode.kind === 'module' && isCopyShown}
                    />
                ) : null
            }
        >
            <div className="flex flex-col gap-4">
                {shown.categories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        モジュール分類はありません
                    </p>
                ) : (
                    <SortableRows
                        id="module-categories"
                        ids={categoryIds}
                        disabled={isSending}
                    >
                        <ul
                            aria-label="モジュール分類"
                            className="flex flex-col gap-1"
                        >
                            {shown.categories.map((category) => (
                                <CategoryItem
                                    key={category.id}
                                    category={category}
                                    isSelected={category.id === selectedId}
                                    onSelect={() => onSelect(category.id)}
                                    disabled={isSending}
                                    isDropParent={dropParent === category.id}
                                />
                            ))}
                        </ul>
                    </SortableRows>
                )}
                <section
                    aria-labelledby="uncategorized-heading"
                    className={cn(
                        'flex flex-col gap-1 border-t pt-4',
                        dropParent === null && DROP_PARENT_CLASS,
                    )}
                >
                    <UncategorizedHeading />
                    {shown.uncategorized.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            分類なしの業務モジュールはありません
                        </p>
                    ) : (
                        <ModuleList
                            parent={null}
                            label="分類なしの業務モジュール"
                            modules={visible}
                            disabled={isSending}
                        >
                            {hidden.map((module) => (
                                <li
                                    key={module.code}
                                    className="flex items-center gap-1 text-muted-foreground"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="size-8 shrink-0"
                                    />
                                    <span className="text-sm">
                                        {module.name}
                                    </span>
                                    <Badge variant="outline">非表示</Badge>
                                </li>
                            ))}
                        </ModuleList>
                    )}
                </section>
            </div>
        </SortableArea>
    );
}
