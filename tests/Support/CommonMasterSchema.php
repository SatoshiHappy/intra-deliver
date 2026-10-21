<?php

namespace Tests\Support;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectCategoryTranslation;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Authz\RoleTranslation;
use App\Models\Authz\UserRole;
use App\Models\Master\Company;
use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use Illuminate\Database\Eloquent\Model;

/**
 * 共通マスタの 29 表の列(docs の 設計/共通マスタ/ のロールと認可の表の定義と、共通の規則の 4 節・5.1 の型。組織とユーザーの表の列の期待は
 * ここに置く = docs の 設計/共通マスタ/組織とユーザー.md は意味と規則だけを持つ)。
 *
 * 表 → 列 → 「型 [not null] [default 既定値]」(PostgreSQL の format_type と pg_get_expr の書き方)。
 * すべての表にある id・created_at・updated_at は省く(照合する側が足す)。
 */
final class CommonMasterSchema
{
    private const CODE = 'character varying(100) not null';

    private const SORT_KEY = "bigint not null default '0'::bigint";

    private const FLAG = 'boolean not null default false';

    private const KEY = 'bigint not null';

    private const NAME = 'character varying(1000) not null';

    private const TEXT_1000 = 'character varying(1000)';

    private const TEXT_100 = 'character varying(100)';

    private const TEXT_50 = 'character varying(50)';

    private const TEXT_256 = 'character varying(256)';

    private const NOTES = 'character varying(4000)';

    /** 認可のリソースタイプ(本家の上限 255 文字) */
    private const RESOURCE_TYPE = 'character varying(255) not null';

    /** 認可のリソースグループ ID(ほかの事柄の ID から作るので、リソースタイプと同じ 255 文字 = docs の 設計/共通マスタ/ロールと認可/リソース.md の authz_resource_groups) */
    private const RESOURCE_GROUP_CODE = 'character varying(255) not null';

    private const PERIOD = ['start_date' => 'date not null', 'end_date' => 'date', 'is_deleted' => self::FLAG];

    /** 住所と電話(組織とユーザーのロケール別の表で共通の並び) */
    private const ADDRESS_AND_PHONE = [
        'country_code' => self::TEXT_100,
        'zip_code' => self::TEXT_50,
        'address1' => self::TEXT_1000,
        'address2' => self::TEXT_1000,
        'address3' => self::TEXT_1000,
        'telephone_number' => self::TEXT_50,
        'extension_number' => self::TEXT_50,
        'fax_number' => self::TEXT_50,
        'extension_fax_number' => self::TEXT_50,
    ];

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'locales' => Locale::class,
        'companies' => Company::class,
        'department_sets' => DepartmentSet::class,
        'departments' => Department::class,
        'department_terms' => DepartmentTerm::class,
        'department_term_translations' => DepartmentTermTranslation::class,
        'department_inclusions' => DepartmentInclusion::class,
        'company_posts' => CompanyPost::class,
        'company_post_terms' => CompanyPostTerm::class,
        'company_post_term_translations' => CompanyPostTermTranslation::class,
        'users' => User::class,
        'user_terms' => UserTerm::class,
        'user_term_translations' => UserTermTranslation::class,
        'department_memberships' => DepartmentMembership::class,
        'department_membership_posts' => DepartmentMembershipPost::class,
        'roles' => Role::class,
        'role_translations' => RoleTranslation::class,
        'role_inclusions' => RoleInclusion::class,
        'user_roles' => UserRole::class,
        'authz_resource_groups' => AuthzResourceGroup::class,
        'authz_resource_group_translations' => AuthzResourceGroupTranslation::class,
        'authz_resource_group_attributes' => AuthzResourceGroupAttribute::class,
        'authz_resources' => AuthzResource::class,
        'authz_subject_groups' => AuthzSubjectGroup::class,
        'authz_subject_group_translations' => AuthzSubjectGroupTranslation::class,
        'authz_subject_categories' => AuthzSubjectCategory::class,
        'authz_subject_category_translations' => AuthzSubjectCategoryTranslation::class,
        'authz_subject_conditions' => AuthzSubjectCondition::class,
        'authz_policies' => AuthzPolicy::class,
    ];

    private const SET_AND_ID = ['department_set_id', 'id'];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ: 参照する側のモデル、factory の状態、列、参照先の表と列、
     * 削除の動作、参照する側のリレーション、参照先の側のリレーション)。参照先のモジュールから参照する側のモジュールへ依存できないとき・
     * 同じ向きのリレーションが別の行にあるときは参照先の側のリレーションを null にする。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}>
     */
    public const FOREIGN_KEYS = [
        [DepartmentSet::class, null, ['company_id'], 'companies', ['id'], 'restrict', 'company', 'departmentSets'],
        [Department::class, null, ['department_set_id'], 'department_sets', ['id'], 'restrict', 'departmentSet', 'departments'],
        [DepartmentTerm::class, null, ['department_id'], 'departments', ['id'], 'cascade', 'department', 'departmentTerms'],
        [DepartmentTermTranslation::class, null, ['department_term_id'], 'department_terms', ['id'], 'cascade', 'departmentTerm', 'departmentTermTranslations'],
        [DepartmentTermTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', 'departmentTermTranslations'],
        [DepartmentInclusion::class, null, ['department_set_id', 'parent_department_id'], 'departments', self::SET_AND_ID, 'restrict', 'parentDepartment', 'inclusionsAsParent'],
        [DepartmentInclusion::class, null, ['department_set_id', 'department_id'], 'departments', self::SET_AND_ID, 'restrict', 'department', 'inclusionsAsChild'],
        [CompanyPost::class, null, ['department_set_id'], 'department_sets', ['id'], 'restrict', 'departmentSet', 'companyPosts'],
        [CompanyPostTerm::class, null, ['company_post_id'], 'company_posts', ['id'], 'cascade', 'companyPost', 'companyPostTerms'],
        [CompanyPostTermTranslation::class, null, ['company_post_term_id'], 'company_post_terms', ['id'], 'cascade', 'companyPostTerm', 'companyPostTermTranslations'],
        [CompanyPostTermTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', 'companyPostTermTranslations'],
        [UserTerm::class, null, ['user_id'], 'users', ['id'], 'cascade', 'user', 'userTerms'],
        [UserTermTranslation::class, null, ['user_term_id'], 'user_terms', ['id'], 'cascade', 'userTerm', 'userTermTranslations'],
        [UserTermTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', 'userTermTranslations'],
        [DepartmentMembership::class, null, ['department_set_id', 'department_id'], 'departments', self::SET_AND_ID, 'restrict', 'department', 'departmentMemberships'],
        [DepartmentMembership::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', 'departmentMemberships'],
        [DepartmentMembershipPost::class, null, ['department_set_id', 'department_membership_id'], 'department_memberships', self::SET_AND_ID, 'cascade', 'departmentMembership', 'departmentMembershipPosts'],
        [DepartmentMembershipPost::class, null, ['department_set_id', 'company_post_id'], 'company_posts', self::SET_AND_ID, 'restrict', 'companyPost', 'departmentMembershipPosts'],
        [RoleTranslation::class, null, ['role_id'], 'roles', ['id'], 'cascade', 'role', 'roleTranslations'],
        [RoleTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [RoleInclusion::class, null, ['parent_role_id'], 'roles', ['id'], 'restrict', 'parentRole', 'inclusionsAsParent'],
        [RoleInclusion::class, null, ['child_role_id'], 'roles', ['id'], 'restrict', 'childRole', 'inclusionsAsChild'],
        [UserRole::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
        [UserRole::class, null, ['role_id'], 'roles', ['id'], 'restrict', 'role', 'userRoles'],
        [AuthzResourceGroup::class, 'withParent', ['parent_id'], 'authz_resource_groups', ['id'], 'restrict', 'parent', 'children'],
        [AuthzResourceGroupTranslation::class, null, ['authz_resource_group_id'], 'authz_resource_groups', ['id'], 'cascade', 'authzResourceGroup', 'authzResourceGroupTranslations'],
        [AuthzResourceGroupTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [AuthzResourceGroupAttribute::class, null, ['authz_resource_group_id'], 'authz_resource_groups', ['id'], 'cascade', 'authzResourceGroup', 'authzResourceGroupAttributes'],
        [AuthzResource::class, null, ['authz_resource_group_id'], 'authz_resource_groups', ['id'], 'restrict', 'authzResourceGroup', 'authzResource'],
        [AuthzSubjectCondition::class, null, ['authz_subject_group_id'], 'authz_subject_groups', ['id'], 'cascade', 'authzSubjectGroup', 'authzSubjectConditions'],
        [AuthzSubjectCondition::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
        [AuthzSubjectCondition::class, 'forDepartment', ['department_id'], 'departments', ['id'], 'restrict', 'department', null],
        [AuthzSubjectCondition::class, 'forCompanyPost', ['company_post_id'], 'company_posts', ['id'], 'restrict', 'companyPost', null],
        [AuthzSubjectCondition::class, 'forRole', ['role_id'], 'roles', ['id'], 'restrict', 'role', 'authzSubjectConditions'],
        [AuthzSubjectCondition::class, 'forChildSubjectGroup', ['child_authz_subject_group_id'], 'authz_subject_groups', ['id'], 'restrict', 'childAuthzSubjectGroup', 'authzSubjectConditionsAsChild'],
        [AuthzSubjectGroupTranslation::class, null, ['authz_subject_group_id'], 'authz_subject_groups', ['id'], 'cascade', 'authzSubjectGroup', 'authzSubjectGroupTranslations'],
        [AuthzSubjectGroupTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [AuthzSubjectCategoryTranslation::class, null, ['authz_subject_category_id'], 'authz_subject_categories', ['id'], 'cascade', 'authzSubjectCategory', 'authzSubjectCategoryTranslations'],
        [AuthzSubjectCategoryTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [AuthzPolicy::class, null, ['authz_resource_group_id'], 'authz_resource_groups', ['id'], 'restrict', 'authzResourceGroup', 'authzPolicies'],
        [AuthzPolicy::class, null, ['authz_subject_group_id'], 'authz_subject_groups', ['id'], 'restrict', 'authzSubjectGroup', 'authzPolicies'],
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'locales' => ['code' => self::CODE, 'sort_key' => self::SORT_KEY],
        'companies' => ['code' => self::CODE, 'sort_key' => self::SORT_KEY],
        'department_sets' => ['company_id' => self::KEY, 'code' => self::CODE, 'is_default' => self::FLAG, 'sort_key' => self::SORT_KEY],
        'departments' => ['department_set_id' => self::KEY, 'code' => self::CODE, 'is_root' => self::FLAG, 'sort_key' => self::SORT_KEY],
        'department_terms' => ['department_id' => self::KEY, ...self::PERIOD],
        'department_term_translations' => [
            'department_term_id' => self::KEY,
            'locale_id' => self::KEY,
            'name' => self::NAME,
            'short_name' => self::TEXT_1000,
            'search_name' => self::TEXT_1000,
            ...self::ADDRESS_AND_PHONE,
            'email_address1' => self::TEXT_256,
            'email_address2' => self::TEXT_256,
            'url' => self::TEXT_1000,
            'notes' => self::NOTES,
        ],
        'department_inclusions' => [
            'department_set_id' => self::KEY,
            'parent_department_id' => self::KEY,
            'department_id' => self::KEY,
            ...self::PERIOD,
            'sort_key' => self::SORT_KEY,
        ],
        'company_posts' => ['department_set_id' => self::KEY, 'code' => self::CODE, 'rank' => 'integer not null', 'sort_key' => self::SORT_KEY],
        'company_post_terms' => ['company_post_id' => self::KEY, ...self::PERIOD],
        'company_post_term_translations' => ['company_post_term_id' => self::KEY, 'locale_id' => self::KEY, 'name' => self::NAME, 'notes' => self::NOTES],
        'users' => ['code' => self::CODE, 'sort_key' => self::SORT_KEY],
        'user_terms' => ['user_id' => self::KEY, ...self::PERIOD, 'sex' => 'character varying(1)'],
        'user_term_translations' => [
            'user_term_id' => self::KEY,
            'locale_id' => self::KEY,
            'name' => self::NAME,
            'search_name' => self::TEXT_1000,
            ...self::ADDRESS_AND_PHONE,
            'mobile_number' => self::TEXT_50,
            'email_address1' => self::TEXT_256,
            'email_address2' => self::TEXT_256,
            'mobile_email_address' => self::TEXT_256,
            'url' => self::TEXT_1000,
            'notes' => self::NOTES,
        ],
        'department_memberships' => [
            'department_set_id' => self::KEY,
            'department_id' => self::KEY,
            'user_id' => self::KEY,
            ...self::PERIOD,
            'is_main' => self::FLAG,
            'sort_key' => self::SORT_KEY,
        ],
        'department_membership_posts' => ['department_set_id' => self::KEY, 'department_membership_id' => self::KEY, 'company_post_id' => self::KEY],
        'roles' => ['code' => self::CODE, 'name' => self::NAME, 'category' => self::TEXT_1000, 'notes' => self::NOTES, 'sort_key' => self::SORT_KEY],
        'role_translations' => ['role_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME],
        'role_inclusions' => ['parent_role_id' => self::KEY, 'child_role_id' => self::KEY],
        'user_roles' => ['user_id' => self::KEY, 'role_id' => self::KEY, ...self::PERIOD],
        'authz_resource_groups' => ['code' => self::RESOURCE_GROUP_CODE, 'parent_id' => 'bigint', 'sort_key' => self::SORT_KEY],
        'authz_resource_group_translations' => ['authz_resource_group_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME, 'description' => self::NOTES],
        'authz_resource_group_attributes' => ['authz_resource_group_id' => self::KEY, 'key' => 'character varying(100) not null', 'value' => 'character varying(4000) not null'],
        'authz_resources' => ['authz_resource_group_id' => self::KEY, 'resource_type' => self::RESOURCE_TYPE, 'uri' => self::NAME],
        'authz_subject_groups' => ['combination' => 'character varying(20) not null', 'sort_key' => self::SORT_KEY],
        'authz_subject_group_translations' => ['authz_subject_group_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME, 'description' => self::NOTES],
        'authz_subject_categories' => ['code' => self::CODE, 'sort_key' => self::SORT_KEY],
        'authz_subject_category_translations' => ['authz_subject_category_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME],
        'authz_subject_conditions' => [
            'authz_subject_group_id' => self::KEY,
            'user_id' => 'bigint',
            'department_id' => 'bigint',
            'company_post_id' => 'bigint',
            'role_id' => 'bigint',
            'operator' => "character varying(20) not null default 'eq'::character varying",
        ],
        'authz_policies' => [
            'authz_resource_group_id' => self::KEY,
            'authz_subject_group_id' => self::KEY,
            'action' => 'character varying(100) not null',
            'effect' => 'character varying(20) not null',
        ],
    ];

    /**
     * 足す形のマイグレーションで後から足した列(表 → 列 → 定義。書き方は COLUMNS と同じ)。
     * 物理の並びでは created_at・updated_at の後に来る(PostgreSQL は列を途中に足せない)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS_ADDED_LATER = [
        'authz_policies' => [
            'resource_type' => self::RESOURCE_TYPE,
        ],
        'authz_subject_groups' => [
            'purpose' => "character varying(20) not null default 'policy'::character varying",
        ],
        'authz_subject_conditions' => [
            'ipv4_pattern' => 'character varying(39)',
            'meta_subject' => 'character varying(20)',
            'term_start_date' => 'date',
            'term_end_date' => 'date',
            'child_authz_subject_group_id' => 'bigint',
            'is_negated' => self::FLAG,
        ],
    ];
}
