import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import {
    FormBlock,
    formErrorId,
    invalidProps,
    UnshownErrors,
} from '@/components/FormBlock';
import {
    FormActions,
    FormSurface,
    TWO_SURFACES_GRID,
} from '@/components/FormLayout';
import { MenuIcon } from '@/components/MenuIcon';
import { RecordRowAccessScopeField } from '@/components/Records/RecordRowAccessScopeField';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    initialModuleData,
    toModulePayload,
} from '@/lib/Records/recordModuleForm';
import { index as categoriesIndex } from '@/routes/records/admin/categories';
import { edit, index, show, update } from '@/routes/records/admin/modules';
import type {
    DefinitionTextMaxLengths,
    ModuleCategoryChoice,
    RecordAdminModuleForm,
    RecordModuleIconChoice,
} from '@/types';

/** 画面名(見出し・パンくず・題) */
const HEADING = '業務モジュールの編集';

/** チェック「サイドバーに表示」の説明(画面/管理の画面.md 3.5) */
const VISIBILITY_HINT =
    'チェックを解除するとサイドバーとサイトマップに表示されなくなります。レコードと定義は保持され、URL を直接指定すると表示できます。非表示にするとモジュール分類と位置の設定は解除されます。再表示するときにモジュール分類を再選択できます。選択しなければ「業務」の直下の末尾に表示されます';

/** 「モジュール分類」のチェックの一覧の説明(モジュール分類/画面と検査.md 4.2) */
const CATEGORIES_HINT =
    '選択したモジュール分類のフォルダに表示されます。選択しなければ「業務」の直下に表示されます';

/**
 * icons = アイコンの選択肢(作成と同じ)、categories = すべてのモジュール分類(並びの順)、textMaxLengths = 名前・説明の文字数の上限
 */
type Props = {
    module: RecordAdminModuleForm;
    icons: RecordModuleIconChoice[];
    categories: ModuleCategoryChoice[];
    textMaxLengths: DefinitionTextMaxLengths;
};

/**
 * 枠「属性」のチェック「サイドバーに表示」(説明と、is_visible の誤り = アイテムを作る・消す段の失敗を含む)。
 */
function VisibilityFlag({
    checked,
    onChange,
    error,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    error: string | undefined;
}) {
    const id = 'module-is_visible';

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(next) => onChange(next === true)}
                    {...invalidProps(id, error)}
                />
                <Label htmlFor={id}>サイドバーに表示</Label>
            </div>
            <p className="text-xs text-muted-foreground">{VISIBILITY_HINT}</p>
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

/**
 * 管理の業務モジュールの編集(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5、モジュール分類/画面と検査.md 4.2)。
 * 欄として描いていないキーの誤りはフォームの面の先頭に出す(3 節の冒頭)。
 */
export default function ModuleEdit({
    module,
    icons,
    categories,
    textMaxLengths,
}: Props) {
    const form = useForm(initialModuleData(module));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            toModulePayload(data, module.isDetails, categories),
        );
        form.submit(update(module.code));
    };

    const basic = (
        <FormSurface aria-labelledby="module-basic" inner="max-w-2xl">
            <h2 id="module-basic" className="text-[15px] font-semibold">
                基本
            </h2>
            <div className="flex flex-col gap-2">
                <p className="text-sm">
                    <span className="text-muted-foreground">コード: </span>
                    {module.code}
                </p>
                <p className="text-xs text-muted-foreground">
                    作成後は変更できません
                </p>
            </div>
            <FormBlock
                id="module-name"
                label="名前"
                isRequired
                error={form.errors.name}
            >
                <CountedTextInput
                    id="module-name"
                    value={form.data.name}
                    max={textMaxLengths.name}
                    invalid={form.errors.name !== undefined}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </FormBlock>
            <FormBlock
                id="module-description"
                label="説明"
                error={form.errors.description}
            >
                <CountedTextInput
                    id="module-description"
                    isMultiline
                    value={form.data.description}
                    max={textMaxLengths.description}
                    invalid={form.errors.description !== undefined}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
            </FormBlock>
            {!module.isDetails && (
                <FormBlock
                    id="module-icon"
                    label="アイコン"
                    hint="サイドバーとサイトマップに表示されます"
                    error={form.errors.icon}
                >
                    <Select
                        value={form.data.icon}
                        onValueChange={(icon) => form.setData('icon', icon)}
                    >
                        <SelectTrigger
                            id="module-icon"
                            className="w-full"
                            {...invalidProps('module-icon', form.errors.icon)}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {icons.map((icon) => (
                                <SelectItem key={icon.value} value={icon.value}>
                                    <MenuIcon
                                        name={icon.value}
                                        className="size-4"
                                    />
                                    {icon.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormBlock>
            )}
        </FormSurface>
    );

    return (
        <>
            <Head title={HEADING} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{HEADING}</h1>
                <form
                    noValidate
                    onSubmit={submit}
                    aria-label="業務モジュールの入力"
                    className="flex flex-col gap-6"
                >
                    <UnshownErrors
                        errors={
                            form.errors as Record<string, string | undefined>
                        }
                        shownKeys={
                            module.isDetails
                                ? ['name', 'description']
                                : [
                                      'name',
                                      'description',
                                      'icon',
                                      'is_visible',
                                      'categories',
                                      'row_access_scope',
                                  ]
                        }
                    />
                    {module.isDetails ? (
                        basic
                    ) : (
                        <div className={TWO_SURFACES_GRID}>
                            {basic}
                            <FormSurface
                                aria-labelledby="module-attributes"
                                inner="max-w-2xl"
                                gap={4}
                            >
                                <h2
                                    id="module-attributes"
                                    className="text-[15px] font-semibold"
                                >
                                    属性
                                </h2>
                                <VisibilityFlag
                                    checked={form.data.is_visible}
                                    onChange={(checked) =>
                                        form.setData('is_visible', checked)
                                    }
                                    error={form.errors.is_visible}
                                />
                                <CategoryChecklist
                                    categories={categories}
                                    selected={form.data.categories}
                                    disabled={!form.data.is_visible}
                                    onChange={(selected) =>
                                        form.setData('categories', selected)
                                    }
                                    error={form.errors.categories}
                                />
                                <RecordRowAccessScopeField
                                    value={form.data.row_access_scope}
                                    onChange={(scope) =>
                                        form.setData('row_access_scope', scope)
                                    }
                                    error={form.errors.row_access_scope}
                                />
                            </FormSurface>
                        </div>
                    )}
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={show(module.code)}>キャンセル</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            更新
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

ModuleEdit.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '業務モジュール', href: index() },
        { title: props.module.name, href: show(props.module.code) },
        { title: HEADING, href: edit(props.module.code) },
    ],
});
