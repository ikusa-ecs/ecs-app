{{-- 社員側の画面で共通して使う骨組み（土台）。各画面はこれを @extends して中身だけ書く。 --}}
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS @yield('title')</title>
  <link rel="stylesheet" href="/ecs/style.css">
  {{-- 前回「メニューをたたんだ」状態を、画面が表示される前に復元する（一瞬メニューが見えてから消える"チラつき"を防ぐ）。 --}}
  <script>
    try { if (localStorage.getItem('ecs_nav_hidden') === '1') document.documentElement.classList.add('nav-collapsed'); } catch (e) {}
  </script>
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
      {{-- ☰＝左メニューの開閉ボタン。押すたびにメニューをたたむ／戻す。 --}}
      <button type="button" class="nav-toggle" onclick="ECStoggleNav()" aria-label="メニューを開く／閉じる" title="メニューを開く／閉じる">☰</button>
      <h1>@yield('h1')</h1>
      <div class="spacer"></div>
      <span class="role-pill">社員</span>
    </div>
    <div class="content">
      @yield('content')
    </div>
  </div>
</div>

{{-- ☰ボタンで左メニューを開閉する。たたんだ状態はブラウザに記憶する（次回も同じ状態で開く）。 --}}
<script>
  function ECStoggleNav(){
    var hidden = document.documentElement.classList.toggle('nav-collapsed');
    try { localStorage.setItem('ecs_nav_hidden', hidden ? '1' : '0'); } catch (e) {}
  }
</script>

{{-- 各画面のJavaScriptは @push('scripts') でここに入る --}}
@stack('scripts')
</body>
</html>
