{{-- SFの商談の下見：時間（いつも出す）＋残りの項目（「ぜんぶ見る」で開く）。2026-11-20 小沼さん。
     マイページ（partials/sf_my_inbox）と受信箱（sf_inbox）の両方がこれを使う＝2つの画面で出す項目が食い違わないように。
     値はすべて App\Support\SfOpportunityMap::preview の結果（登録と同じ読み替え）。画面で計算しない。
     使い方：表（table）の中で include し、$p（preview の結果）を渡す。開け閉めは partials/sf_inbox_js が受け持つ。 --}}
<tr><th>時間</th><td>集合 {{ $p['start_time'] ?? '—' }}／解散 {{ $p['end_time'] ?? '—' }}／イベント開始 {{ $p['event_start_time'] ?? '—' }}／終了 {{ $p['event_end_time'] ?? '—' }}</td></tr>
<tr><td colspan="2" class="sfx-toggle-cell"><button type="button" class="sfx-toggle" data-sfx-toggle aria-expanded="false">▼ ぜんぶ見る</button></td></tr>
<tbody class="sfx-more" hidden>
  <tr><th>規模</th><td>{{ $p['scale'] ?? '—' }}</td></tr>
  <tr><th>実施形態</th><td>{{ $p['format'] ?? '—' }}</td></tr>
  <tr><th>チーム数</th><td>{{ $p['team_count'] !== null ? $p['team_count'].'チーム' : '—' }}</td></tr>
  <tr><th>宿泊</th><td>{{ $p['lodging'] ?? '—' }}</td></tr>
  <tr><th>オンラインツール</th><td>{{ $p['online_tool_entered'] ?? '—' }}@if ($p['online_tool_entered'] === null && $p['online_tool_raw'] !== null)<span class="sfx-dim">（Salesforce：{{ $p['online_tool_raw'] }}・実施形態がオンラインでないので案件には入れません）</span>@endif</td></tr>
  <tr><th>代理店</th><td>{{ $p['agency'] ?? '—' }}</td></tr>
  <tr><th>金額（参考）</th><td>{{ $p['amount'] !== null ? number_format($p['amount']).'円' : '—' }}<span class="sfx-dim">（ECSの案件には入れません）</span></td></tr>
  <tr><th>Salesforceの備考</th><td style="white-space:pre-wrap;">{{ $p['note'] ?? '—' }}</td></tr>
</tbody>
