@extends('layouts.app')
@section('title', 'マスタ管理')
@section('h1', 'マスタ管理')
@php($active = 'settings')

@push('head')
<style>
    .m-wrap { max-width: 860px; }
    .m-intro { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; }
    .m-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .m-nav a {
      padding: 6px 14px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; font-size: 13px; font-weight: 700; color: var(--brand-dark); text-decoration: none;
    }
    .m-nav a:hover { background: #f3ece0; }

    .flash {
      background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d;
      border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px;
    }
    .m-err {
      background: var(--danger-soft); border: 1px solid #f0b9b9; color: #b91c1c;
      border-radius: 10px; padding: 10px 14px; font-size: 12.5px; margin-bottom: 14px;
    }

    .m-head {
      display: grid; grid-template-columns: 66px 1fr 110px 44px 58px 48px 178px; gap: 8px;
      font-size: 11.5px; font-weight: 700; color: var(--muted); padding: 2px 4px 6px; align-items: end;
    }
    .m-head.off { grid-template-columns: 56px 1fr 96px 52px 150px; }
    .m-row {
      display: grid; grid-template-columns: 66px 1fr 110px 44px 58px 48px 178px; gap: 8px;
      align-items: center; padding: 6px 4px; border-top: 1px solid var(--line);
    }
    .m-row.off { grid-template-columns: 56px 1fr 96px 52px 150px; }
    .m-row.add { border-top: 2px solid #e3d3b6; margin-top: 4px; }
    /* まとめて保存バー */
    .m-save-bar { display: flex; justify-content: flex-end; align-items: center; gap: 12px; padding: 12px 4px 2px; }
    .m-save-bar .m-btn { padding: 9px 20px; font-size: 13px; }
    /* 上下の並び替えボタン */
    .m-move { display: flex; gap: 4px; justify-content: center; }
    .m-move button { padding: 4px 9px; font-size: 13px; line-height: 1; }
    .m-move button[disabled] { opacity: .35; cursor: default; }
    .m-id { font-size: 12px; color: #8a7a66; font-weight: 700; }
    .m-row input[type=text], .m-row input[type=number] {
      padding: 6px 8px; border: 1px solid var(--line); border-radius: 7px;
      font-size: 13px; font-family: inherit; width: 100%; box-sizing: border-box; background: #fff;
    }
    .m-chk { font-size: 12px; color: var(--ink); display: inline-flex; align-items: center; gap: 4px; justify-content: center; }
    .m-acts { display: flex; gap: 6px; }
    .m-btn {
      padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer;
      font-family: inherit; border: 1px solid var(--line); background: #fff; color: var(--brand-dark);
    }
    .m-btn.primary { background: var(--brand); border-color: var(--brand-dark); color: #fff; }
    .m-btn.danger { color: var(--danger); border-color: #f0b9b9; }
    .m-btn:hover { filter: brightness(.97); }
    a.m-btn { text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap; }

    .m-fixed { display: grid; grid-template-columns: 90px 1fr; gap: 6px 12px; font-size: 13px; }
    .m-fixed .code { font-weight: 800; color: var(--brand-dark); }
    .m-note { font-size: 11.5px; color: var(--muted); margin: 10px 0 0; }
</style>
@endpush

@section('content')
  @if (session('status'))
    <div class="flash">✓ {{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="m-err">入力を確認してください：{{ $errors->first() }}</div>
  @endif

  <div class="m-nav">
    <a href="#contents">コンテンツ</a>
    <a href="#offices">拠点（事務所）</a>
    <a href="#positions">ポジション（役割）</a>
    <a href="/settings">← 設定に戻る</a>
  </div>

  {{-- 削除権限の注釈（1か所にまとめて記載）。Administrator以外には削除ボタンを出さない。 --}}
  @if (optional(Auth::user())->permission !== 'admin')
    <p class="m-note" style="margin:0 0 14px;">※ コンテンツ・拠点の<b>削除</b>は Administrator のみ行えます（追加・編集はどなたでも可能です）。</p>
  @endif

  {{-- ── コンテンツ ── --}}
  <div class="panel m-wrap" id="contents">
    <div class="panel-head"><h2>コンテンツ</h2></div>
    <p class="m-intro">案件名に使うコンテンツ（水合戦・運動会 など）。ここを直すと案件登録の選択肢に反映されます。<br>
      「紙」＝謎解きシートが必要なコンテンツ。オンにすると <a href="/paper-stock">謎解きの紙 在庫</a> で集計されます（枚/組＝1チームあたりの必要枚数・基本1）。</p>

    <form method="POST" action="/masters/contents/bulk">
      @csrf
      <div class="m-head">
        <span>ID</span><span>コンテンツ名</span><span>分類</span><span>紙</span><span>枚/組</span><span>有効</span><span>操作</span>
      </div>
      @foreach ($contents as $c)
        <div class="m-row">
          <span class="m-id">{{ $c->id }}</span>
          <input type="text" name="rows[{{ $c->id }}][content_name]" value="{{ $c->content_name }}" required>
          <input type="text" name="rows[{{ $c->id }}][category]" value="{{ $c->category }}" placeholder="分類">
          <label class="m-chk"><input type="checkbox" name="rows[{{ $c->id }}][needs_paper]" value="1" @checked($c->needs_paper)></label>
          <input type="number" name="rows[{{ $c->id }}][sheets_per_team]" value="{{ $c->sheets_per_team ?? 1 }}" min="1" max="99" title="1チームあたりの必要枚数">
          <label class="m-chk"><input type="checkbox" name="rows[{{ $c->id }}][active]" value="1" @checked($c->active)></label>
          <div class="m-acts">
            <a class="m-btn" href="/masters/contents/{{ $c->id }}/requirements" title="規模ごとの必要人数を設定">必要人数</a>
            {{-- 削除は Administrator のみ（サーバー側も tier:admin で保護）。他の役割にはボタンを出さない（注釈は画面上部にまとめて記載）。 --}}
            @if (optional(Auth::user())->permission === 'admin')
            <button type="submit" class="m-btn danger" formaction="/masters/contents/{{ $c->id }}/delete"
                    formnovalidate onclick="return confirm('「{{ $c->content_name }}」を削除しますか？')">削除</button>
            @endif
          </div>
        </div>
      @endforeach
      <div class="m-save-bar">
        <span class="m-note" style="margin:0;">編集した内容をまとめて保存します。</span>
        <button type="submit" class="m-btn primary">すべて保存</button>
      </div>
    </form>

    <form class="m-row add" method="POST" action="/masters/contents">
      @csrf
      <span class="m-id">新規</span>
      <input type="text" name="content_name" placeholder="コンテンツ名" required>
      <input type="text" name="category" placeholder="分類（任意）">
      <label class="m-chk"><input type="checkbox" name="needs_paper" value="1"></label>
      <input type="number" name="sheets_per_team" value="1" min="1" max="99" title="1チームあたりの必要枚数">
      <label class="m-chk"><input type="checkbox" name="active" value="1" checked></label>
      <div class="m-acts"><button type="submit" class="m-btn primary">＋ 追加</button></div>
    </form>
  </div>

  {{-- ── 拠点（事務所）── --}}
  <div class="panel m-wrap" id="offices" style="margin-top:16px;">
    <div class="panel-head"><h2>拠点（事務所）</h2></div>
    <p class="m-intro">社員・スタッフの所属事務所（東京・大阪 など）。登録画面の「事務所」の選択肢の元になります。▲▼ボタンで並び順を変えられます。</p>

    <form method="POST" action="/masters/offices/bulk">
      @csrf
      <div class="m-head off">
        <span>ID</span><span>拠点名</span><span>並び替え</span><span>有効</span><span>操作</span>
      </div>
      @foreach ($offices as $o)
        <div class="m-row off">
          <span class="m-id">{{ $o->id }}</span>
          <input type="text" name="rows[{{ $o->id }}][name]" value="{{ $o->name }}" required>
          <div class="m-move">
            <button type="submit" class="m-btn" formaction="/masters/offices/{{ $o->id }}/up/move" formnovalidate title="上へ" @disabled($loop->first)>▲</button>
            <button type="submit" class="m-btn" formaction="/masters/offices/{{ $o->id }}/down/move" formnovalidate title="下へ" @disabled($loop->last)>▼</button>
          </div>
          <label class="m-chk"><input type="checkbox" name="rows[{{ $o->id }}][active]" value="1" @checked($o->active)></label>
          <div class="m-acts">
            {{-- 削除は Administrator のみ（サーバー側も tier:admin で保護）。他の役割にはボタンを出さない（注釈は画面上部にまとめて記載）。 --}}
            @if (optional(Auth::user())->permission === 'admin')
            <button type="submit" class="m-btn danger" formaction="/masters/offices/{{ $o->id }}/delete"
                    formnovalidate onclick="return confirm('拠点「{{ $o->name }}」を削除しますか？')">削除</button>
            @endif
          </div>
        </div>
      @endforeach
      <div class="m-save-bar">
        <span class="m-note" style="margin:0;">拠点名・有効をまとめて保存します（並び順は▲▼で即反映）。</span>
        <button type="submit" class="m-btn primary">すべて保存</button>
      </div>
    </form>

    <form class="m-row off add" method="POST" action="/masters/offices">
      @csrf
      <span class="m-id">新規</span>
      <input type="text" name="name" placeholder="拠点名" required>
      <div class="m-move" style="color:var(--muted); font-size:11px; align-items:center;">末尾に追加</div>
      <label class="m-chk"><input type="checkbox" name="active" value="1" checked></label>
      <div class="m-acts"><button type="submit" class="m-btn primary">＋ 追加</button></div>
    </form>
  </div>

  {{-- ── ポジション（役割）＝表示のみ ── --}}
  <div class="panel m-wrap" id="positions" style="margin-top:16px;">
    <div class="panel-head"><h2>ポジション（役割）</h2></div>
    <p class="m-intro">アサインで使う役割の一覧です。</p>
    <div class="m-fixed">
      @foreach ($positions as $code => $label)
        <span class="code">{{ $code }}</span><span>{{ $label }}</span>
      @endforeach
    </div>
    <p class="m-note">
      ※ 役割コード（D／SD／OP…）は、アサインや実績集計など多くの画面で共通して使う「システムの土台」です。
      取り違えを防ぐため、ここでは追加・変更できません（変更が必要なときは開発で対応します）。
    </p>
  </div>
@endsection
