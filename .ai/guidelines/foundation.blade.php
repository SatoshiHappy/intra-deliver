{{-- Boost の foundation の上書き(vendor/laravel/boost/.ai/foundation.blade.php のコピー)。このプロジェクトの決定と食い違う 2 行だけを直した。Boost を上げたら元と見比べる。 --}}
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Boost Guidelines

## Foundational Context
This application is a Laravel application running on PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }}. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `{{ $assist->composerCommand('show --direct') }}` to list direct dependencies with versions, or `{{ $assist->composerCommand('show <vendor/package>') }}` for a single package.
- JS packages: check `package.json` for the installed versions.

@if (! empty(config('boost.purpose')))
Application purpose: {!! config('boost.purpose') !!}

@endif

@if($assist->hasSkillsEnabled())
## Skills Activation
This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.
@endif

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- 依存を足す・変えるのに承認は要らない。足した依存は報告に書く。外部のサービスの採用と有料の依存は、先にユーザーに確かめる。

## Frontend Bundling
- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `{{ $assist->nodePackageManagerCommand('run build') }}` or ask the user to run `{{ $assist->nodePackageManagerCommand('run dev') }}` or `{{ $assist->composerCommand('run dev') }}`.

## Documentation Files
- web に文書のファイルを作るのは、頼まれたときだけ。設計と経緯の文書は web に置かず、docs のリポジトリの規約(docs の README.md)に従って置く。AI の規則(`.ai/`)・skill(`.agents/skills/`・`.claude/skills/`)・README.md は、この行の外。
