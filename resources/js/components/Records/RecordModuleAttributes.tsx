import { Link } from '@inertiajs/react';
import { formErrorId, invalidProps } from '@/components/FormBlock';
import { FormSurface } from '@/components/FormLayout';
import { RecordRowAccessScopeField } from '@/components/Records/RecordRowAccessScopeField';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { index as categoriesIndex } from '@/routes/records/admin/categories';
import type { ModuleFormData } from '@/lib/Records/recordModuleForm';
import type { ModuleCategoryChoice } from '@/types';

/** チェック「サイドバーに表示」の説明(画面/管理の画面.md 3.5) */
const VISIBILITY_HINT =
    'チェックを解除するとサイドバーとサイトマップに表示されなくなります。レコードと定義は保持され、URL を直接指定すると表示できます。非表示にするとモジュール分類と位置の設定は解除されます。再表示するときにモジュール分類を再選択できます。選択しなければ「業務」の直下の末尾に表示されます';

/** 「モジュール分類」のチェックの一覧の説明(モジュール分類/画面と検査.md 4.2) */
const CATEGORIES_HINT =
    '選択したモジュール分類のフォルダに表示されます。選択しなければ「業務」の直下に表示されます';

/**
 * 枠「属性」のチェック「サイドバーに表示」(説明と、is_visible の誤り = アイテムを作る・消す段の失敗を含む)。
 * 表示の止めがあるとき(stopReason が null でない = ADR 0031)は、チェックを無効にし、ふだんの説明の代わりに止めの理由だけを出して
 * チェックの説明として読み上げに結ぶ(画面/管理の画面.md 3.5)。外した形は、見せる値と送る値を 1 か所で決めるため、呼び手のフォームの値(偽)で描く。
 */
function VisibilityFlag({
    checked,
    onChange,
    error,
    stopReason,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    error: string | undefined;
    stopReason: string | null;
}) {
    const id = 'module-is_visible';
    const stopReasonId = `${id}-stop-reason`;
    const invalid = invalidProps(id, error);
    const describedBy = [
        invalid['aria-describedby'],
        stopReason === null ? undefined : stopReasonId,
    ].join(' ');

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={stopReason !== null}
                    onCheckedChange={(next) => onChange(next === true)}
                    {...invalid}
                    aria-describedby={describedBy.trim() || undefined}
                />
                <Label htmlFor={id}>サイドバーに表示</Label>
            </div>
            <p
                id={stopReason === null ? undefined : stopReasonId}
                className="text-xs text-muted-foreground"
            >
                {stopReason ?? VISIBILITY_HINT}
            </p>
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * 枠「属性」の「モジュール分類」のチェックの一覧(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.2)。
 * disabled の間も選んだ状態は呼び手が保つ(「サイドバーに表示」を付け直したときに戻せるように)。
 */
function CategoryChecklist({
    categories,
    selected,
    disabled,
    onChange,
    error,
}: {
    categories: ModuleCategoryChoice[];
    selected: string[];
    disabled: boolean;
    onChange: (selected: string[]) => void;
    error: string | undefined;
}) {
    const id = 'module-categories';

    return (
        <div
            role="group"
            aria-labelledby={`${id}-label`}
            aria-describedby={error === undefined ? undefined : formErrorId(id)}
            className="flex flex-col gap-2"
        >
            <p id={`${id}-label`} className="text-sm font-medium">
                モジュール分類
            </p>
            {categories.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    モジュール分類はありません。
                    <Link
                        href={categoriesIndex()}
                        className="text-primary underline-offset-4 hover:underline"
                    >
                        モジュール分類の画面
                    </Link>
                    で作成できます
                </p>
            ) : (
                <>
                    <ul className="flex flex-col gap-2">
                        {categories.map((category) => (
                            <li
                                key={category.id}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`${id}-${category.id}`}
                                    checked={selected.includes(category.id)}
                                    disabled={disabled}
                                    onCheckedChange={(next) =>
                                        onChange(
                                            next === true
                                                ? [...selected, category.id]
                                                : selected.filter(
                                                      (selectedId) =>
                                                          selectedId !==
                                                          category.id,
                                                  ),
                                        )
                                    }
                                />
                                <Label htmlFor={`${id}-${category.id}`}>
                                    {category.name}
                                </Label>
                            </li>
                        ))}
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        {CATEGORIES_HINT}
                    </p>
                </>
            )}
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/** チェック「添付ファイルの追加・削除を許可」の説明(レコードの共通機能/業務モジュールの管理.md の 2 節) */
const ATTACHMENT_HINT =
    'チェックを解除すると、詳細の画面で添付ファイルを追加・削除できなくなります。すでに付いている添付ファイルは削除されず、表示できます。申請の添付ファイルも、この業務モジュールで許可する場合だけ追加・削除できます。';

/**
 * 枠「属性」のチェック「添付ファイルの追加・削除を許可」(「サイドバーに表示」の下。明細のモジュールには出さない。説明と、誤りの文言を含む)。
 */
function AttachmentFlag({
    checked,
    onChange,
    error,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    error: string | undefined;
}) {
    const id = 'module-is_attachment_enabled';

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(next) => onChange(next === true)}
                    {...invalidProps(id, error)}
                />
                <Label htmlFor={id}>添付ファイルの追加・削除を許可</Label>
            </div>
            <p className="text-xs text-muted-foreground">{ATTACHMENT_HINT}</p>
            {error !== undefined && (
                <p id={formErrorId(id)} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/** 枠「属性」の props(visibilityStopReason = 表示の止めの理由。止めが無ければ null) */
type ModuleAttributesProps = {
    data: ModuleFormData;
    errors: Partial<Record<keyof ModuleFormData, string>>;
    categories: ModuleCategoryChoice[];
    visibilityStopReason: string | null;
    onChange: <K extends keyof ModuleFormData>(
        key: K,
        value: ModuleFormData[K],
    ) => void;
};

/**
 * 枠「属性」(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5、レコードの共通機能/業務モジュールの管理.md の 2 節。明細のモジュールには出さない):
 * 並び = サイドバーに表示 → モジュール分類(表示の選択)→ 添付ファイルの追加・削除を許可 → 見える範囲。
 */
export function ModuleAttributes({
    data,
    errors,
    categories,
    visibilityStopReason,
    onChange,
}: ModuleAttributesProps) {
    return (
        <FormSurface
            aria-labelledby="module-attributes"
            inner="max-w-2xl"
            gap={4}
        >
            <h2 id="module-attributes" className="text-[15px] font-semibold">
                属性
            </h2>
            <VisibilityFlag
                checked={data.is_visible}
                onChange={(checked) => onChange('is_visible', checked)}
                error={errors.is_visible}
                stopReason={visibilityStopReason}
            />
            <CategoryChecklist
                categories={categories}
                selected={data.categories}
                disabled={!data.is_visible}
                onChange={(selected) => onChange('categories', selected)}
                error={errors.categories}
            />
            <AttachmentFlag
                checked={data.is_attachment_enabled}
                onChange={(checked) =>
                    onChange('is_attachment_enabled', checked)
                }
                error={errors.is_attachment_enabled}
            />
            <RecordRowAccessScopeField
                value={data.row_access_scope}
                onChange={(scope) => onChange('row_access_scope', scope)}
                error={errors.row_access_scope}
            />
        </FormSurface>
    );
}
