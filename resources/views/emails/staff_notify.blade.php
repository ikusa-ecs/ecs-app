{{-- スタッフへのお知らせメール本文（アサイン確定／案件公開）。プレーンテキスト。 --}}
{{ $staffName !== '' ? $staffName.' 様' : 'ECS をご利用の皆さま' }}

{{ $headline }}
@foreach ($lines as $label => $value)
@if (trim((string) $value) !== '')
・{{ $label }}： {{ $value }}
@endif
@endforeach

詳しい内容は ECS の「スタッフ画面」でご確認ください。
{{ rtrim(config('app.url'), '/') }}/staff-portal
@if (trim($footer) !== '')

{{ $footer }}
@endif

──────────
このメールは ECS（スタッフアサイン管理）から送信されています。
当日の連絡や集合の合図は、これまでどおり LINE・チャットワークで行います。
