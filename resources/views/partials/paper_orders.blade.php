{{-- 謎解きの紙の追加発注の一覧（2026-10-15）。発注中＝「届いた」「取り消す」／届いたもの＝新しい順に10件。
     canEdit＝ボタンを出すか（全拠点の画面では見るだけ）。 --}}
@if (count($pendingOrders) > 0)
  <div class="table-scroll" style="margin-top:10px;">
  <table class="ps">
    <thead>
      <tr><th class="l">発注中</th><th class="l">拠点</th><th class="l">コンテンツ</th><th>部数</th><th class="l">発注した日</th><th class="l">届く予定</th><th class="l">メモ</th><th class="l">記録した人</th>@if ($canEdit)<th></th>@endif</tr>
    </thead>
    <tbody>
      @foreach ($pendingOrders as $o)
        <tr>
          <td class="l"><span class="tag-future">📦 発注中</span></td>
          <td class="l">{{ $o['office'] }}</td>
          <td class="l">{{ $o['content'] }}</td>
          <td>{{ $o['count'] }}</td>
          <td class="l">{{ $o['ordered'] }}</td>
          <td class="l {{ $o['late'] ? 'short' : '' }}">{{ $o['expected'] ?? '—' }}@if ($o['late']) （予定を過ぎています）@endif</td>
          <td class="l">{{ $o['note'] }}</td>
          <td class="l">{{ $o['by'] }}</td>
          @if ($canEdit)
            <td class="l" style="white-space:nowrap;">
              <form method="post" action="/paper-stock/orders/{{ $o['id'] }}/arrive" style="display:inline;"
                    data-msg="{{ $o['content'] }} {{ $o['count'] }}部が届いた、として{{ $o['office'] }}の入庫数に足します。よろしいですか？"
                    onsubmit="return confirm(this.dataset.msg);">
                @csrf
                <button type="submit" class="ps-btn" style="padding:5px 12px;">届いた</button>
              </form>
              <form method="post" action="/paper-stock/orders/{{ $o['id'] }}/cancel" style="display:inline;"
                    onsubmit="return confirm('この発注の記録を取り消します（入れ間違いのとき用）。よろしいですか？');">
                @csrf
                <button type="submit" style="padding:5px 10px; border:1px solid var(--line); border-radius:8px; background:var(--panel); color:var(--ink); font-family:inherit; cursor:pointer;">取り消す</button>
              </form>
            </td>
          @endif
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
@else
  <p class="ps-note">いま発注中のものはありません。</p>
@endif

@if (count($arrivedOrders) > 0)
  <details style="margin-top:8px;">
    <summary class="ps-note" style="cursor:pointer;">届いた発注（新しい順に{{ count($arrivedOrders) }}件）</summary>
    <div class="table-scroll">
    <table class="ps">
      <thead>
        <tr><th class="l">届いた日</th><th class="l">拠点</th><th class="l">コンテンツ</th><th>部数</th><th class="l">発注した日</th><th class="l">メモ</th><th class="l">「届いた」を押した人</th></tr>
      </thead>
      <tbody>
        @foreach ($arrivedOrders as $o)
          <tr>
            <td class="l">{{ $o['arrived'] }}</td>
            <td class="l">{{ $o['office'] }}</td>
            <td class="l">{{ $o['content'] }}</td>
            <td>+{{ $o['count'] }}</td>
            <td class="l">{{ $o['ordered'] }}</td>
            <td class="l">{{ $o['note'] }}</td>
            <td class="l">{{ $o['arrivedBy'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </details>
@endif
