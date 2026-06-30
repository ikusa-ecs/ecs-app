{{-- 社員側の画面で共通して使う骨組み（土台）。各画面はこれを @extends して中身だけ書く。 --}}
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS @yield('title')</title>
  <link rel="stylesheet" href="/ecs/style.css">
  {{-- 各画面が追加するCSSや読み込みは @push('head') でここに入る --}}
  @stack('head')
</head>
<body>
<div class="app">

  {{-- 左メニュー（共通） --}}
  @include('partials.sidebar')

  {{-- 本文 --}}
  <div class="main">
    <div class="topbar">
      <h1>@yield('h1')</h1>
      <div class="spacer"></div>
      <span class="role-pill">社員</span>
    </div>
    <div class="content">
      @yield('content')
    </div>
  </div>
</div>

{{-- 各画面のJavaScriptは @push('scripts') でここに入る --}}
@stack('scripts')
</body>
</html>
