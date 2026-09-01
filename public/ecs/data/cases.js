/* =====================================================================
   ECS 共通案件データ（正となる1つのリスト）
   ---------------------------------------------------------------------
   この1ファイルを、すべての画面（日別ボード／詳細／公開ボード／案件一覧／
   スタッフ画面）が読み込みます。案件の日付・人数・会場などを直したいときは、
   ここだけ直せば全画面に反映されます。
   ※本番ではサーバ側の1つのデータになります。モックではこのファイルが代わりです。

   off … 今日から何日後の開催か（マイナス＝過去）。モックがいつ開いても
         月グループ／重なりが見えるよう、相対指定にしています。
   おもな項目：
     id        画面共通の案件ID
     content   コンテンツ名（運動会・水合戦 など）
     name      案件名（一覧やボードの見出し用）
     client    会社・団体名
     cat       現場種別（通常/体力/安定重視/育成）
     category  案件区分（通常案件/追加案件）
     need/filled  必要人数 / 充足済み
     dir       ディレクター（公開時は未定が多い）
     place/placeShort/meetPlace  会場（住所）/ 短い会場名 / 集合場所
     meet/leave/enter/evStart/evEnd  集合/解散/入場/開始/終了
     format/fmt/area  実施形態(表示) / 種別コード(real/long/online) / エリア
     scale/sd   規模(大型/中型/小型・案件登録で確定) / サブディレクター
     lodging/recruit/repeat  宿泊 / 募集するか / リピート案件か
     dayType/parentId  本番/予備日/リハ/前日設営 / 本番への紐づけ
     state/status/yomi  ボード状態 / 進行状態 / ヨミ
     mine/tags/pos  自分(baba)担当か / タグ / ポジション充足ランプ
     added       公開ボードで「登録〇日前」を出すための値（マイナス）
     guests/teams/goods/transport/sound/sales  参加者/チーム/物品/移動/音響/営業
     lineSent/handover/script/opSheet  準備チェック / 運営シート
     archived/draft/tentative/note  過去案件か / 下書き / 仮 / メモ
   ===================================================================== */
window.ECS_CASES = [

  // ── 過去（アーカイブ。公開ボード・スタッフ募集には出さない）──
  { id:'past_anniv', content:'周年式典', name:'■■社 周年式典', client:'■■株式会社', cat:'安定重視', category:'通常案件',
    off:-22, need:14, filled:14, dir:'山本', place:'東京都港区台場1-1 ■■ホテル 宴会場', placeShort:'■■ホテル', meetPlace:'会場現地',
    meet:'12:00', leave:'20:00', enter:'12:30', evStart:'13:00', evEnd:'19:30', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'pub', status:'確定', yomi:'確定', mine:true, tags:[], pos:[['MC','ok']], added:-50,
    guests:200, teams:'—', goods:'鈴木', transport:'電車', sound:'会場音響', sales:'baba',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:true, draft:false, tentative:false, note:'' },

  { id:'past_fes', content:'▲▲フェス', name:'▲▲フェス 設営・運営', client:'▲▲株式会社', cat:'通常', category:'通常案件',
    off:-10, need:12, filled:12, dir:'田中', place:'東京都江東区有明3-1 ▲▲ホール', placeShort:'▲▲ホール', meetPlace:'会場現地',
    meet:'08:00', leave:'18:00', enter:'09:00', evStart:'09:30', evEnd:'17:30', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'pub', status:'確定', yomi:'確定', mine:true, tags:[], pos:[['MC','ok']], added:-35,
    guests:180, teams:8, goods:'高橋', transport:'IKUSAカー', sound:'会場音響', sales:'baba',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:true, draft:false, tentative:false, note:'' },

  { id:'enni_school', content:'縁日', name:'▲▲小学校 縁日', client:'▲▲小学校', cat:'育成', category:'通常案件',
    off:-6, need:6, filled:6, dir:'佐藤', place:'千葉県市川市八幡1-1-1 ▲▲小学校', placeShort:'▲▲小学校', meetPlace:'最寄り駅',
    meet:'09:00', leave:'15:00', enter:'09:30', evStart:'10:00', evEnd:'14:30', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:true, dayType:'本番', parentId:null,
    state:'pub', status:'確定', yomi:'確定', mine:false, tags:[], pos:[['MC','ok'],['受付','ok']], added:-30,
    guests:90, teams:6, goods:'田中', transport:'IKUSAカー', sound:'SANWA', sales:'onuma',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:true, draft:false, tentative:false, note:'' },

  // ── 近い日（同じ日の「重なり」が見える例）──
  { id:'board', content:'ボードゲーム大会', name:'☆☆社 ボードゲーム大会', client:'☆☆株式会社', cat:'通常', category:'通常案件',
    off:9, need:8, filled:0, dir:'未定', place:'（未定）', placeShort:'（未定）', meetPlace:'—',
    meet:'09:00', leave:'17:00', enter:'—', evStart:'—', evEnd:'—', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'todo', status:'未着手', yomi:'Cヨミ', mine:true, tags:[], pos:[['MC','none']], added:-3,
    guests:'—', teams:'—', goods:'未定', transport:'ー', sound:'会場音響', sales:'baba',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:true, tentative:false, note:'' },

  { id:'undo_setup', content:'運動会', name:'●●社 運動会 前日設営', client:'●●株式会社', cat:'通常', category:'通常案件',
    off:9, need:4, filled:4, dir:'佐藤', place:'千葉県千葉市美浜区中瀬1-2-3 総合運動公園', placeShort:'総合運動公園', meetPlace:'会場現地',
    meet:'07:00', leave:'18:00', enter:'—', evStart:'—', evEnd:'—', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'中型', sd:'山本', lodging:'無', recruit:true, repeat:false, dayType:'前日設営', parentId:'undo_d1',
    state:'fix', status:'確定', yomi:'確定', mine:true, tags:['前日設営'], pos:[['OP','ok']], added:-22,
    guests:'—', teams:'—', goods:'山本', transport:'IKUSAカー', sound:'クラシックプロ大', sales:'baba',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:false, draft:false, tentative:false, note:'' },

  { id:'undo_d1', content:'運動会', name:'●●社 運動会（1日目）', client:'●●株式会社', cat:'体力', category:'通常案件',
    off:10, need:18, filled:18, dir:'佐藤', place:'千葉県千葉市美浜区中瀬1-2-3 総合運動公園', placeShort:'総合運動公園', meetPlace:'会場現地',
    meet:'07:30', leave:'17:30', enter:'09:00', evStart:'09:30', evEnd:'16:30', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'大型', sd:'山本', lodging:'前泊有', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'pub', status:'確定', yomi:'確定', mine:true, tags:['本番1日目/3','前泊あり'], pos:[['MC','ok'],['軍師','ok'],['FC','ok'],['受付','ok']], added:-22,
    guests:300, teams:12, goods:'山本', transport:'IKUSAカー', sound:'クラシックプロ大', sales:'baba',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:false, draft:false, tentative:false, note:'' },

  { id:'undo_d2', content:'運動会', name:'●●社 運動会（2日目）', client:'●●株式会社', cat:'体力', category:'通常案件',
    off:11, need:18, filled:16, dir:'佐藤', place:'千葉県千葉市美浜区中瀬1-2-3 総合運動公園', placeShort:'総合運動公園', meetPlace:'会場現地',
    meet:'07:30', leave:'17:30', enter:'09:00', evStart:'09:30', evEnd:'16:30', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'大型', sd:'山本', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:'undo_d1',
    state:'adj', status:'調整中', yomi:'確定', mine:true, tags:['本番2日目/3','連勤'], pos:[['MC','ok'],['軍師','ok'],['受付','short']], added:-22,
    guests:300, teams:12, goods:'山本', transport:'IKUSAカー', sound:'クラシックプロ大', sales:'baba',
    lineSent:true, handover:false, script:false, opSheet:'作成中', archived:false, draft:false, tentative:false, note:'' },

  { id:'undo_d3', content:'運動会', name:'●●社 運動会（3日目）', client:'●●株式会社', cat:'体力', category:'通常案件',
    off:12, need:18, filled:12, dir:'佐藤', place:'千葉県千葉市美浜区中瀬1-2-3 総合運動公園', placeShort:'総合運動公園', meetPlace:'会場現地',
    meet:'07:30', leave:'17:30', enter:'09:00', evStart:'09:30', evEnd:'16:30', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'大型', sd:'山本', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:'undo_d1',
    state:'todo', status:'未着手', yomi:'確定', mine:true, tags:['本番3日目/3','連勤'], pos:[['MC','short'],['軍師','ok'],['受付','short']], added:-22,
    guests:300, teams:12, goods:'山本', transport:'IKUSAカー', sound:'クラシックプロ大', sales:'baba',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'mizu', content:'水合戦', name:'〇〇社 水合戦', client:'〇〇株式会社', cat:'体力', category:'通常案件',
    off:12, need:16, filled:14, dir:'鈴木', place:'千葉県柏市柏の葉6-1 〇〇公園（屋外）', placeShort:'〇〇公園（屋外）', meetPlace:'会場現地',
    meet:'08:00', leave:'17:00', enter:'09:30', evStart:'10:00', evEnd:'16:00', format:'イベント東(リアルロング)', fmt:'long', area:'千葉',
    scale:'大型', sd:'高橋', lodging:'前泊有', recruit:true, repeat:true, dayType:'本番', parentId:null,
    state:'todo', status:'未着手', yomi:'確定', mine:true, tags:['前泊あり'], pos:[['MC','ok'],['軍師','ok'],['受付','short']], added:-14,
    guests:120, teams:8, goods:'佐藤', transport:'IKUSAカー2台', sound:'クラシックプロ中', sales:'onuma',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false,
    note:'集合は南口ロータリー。前泊ありのため前日入りの宿手配を要確認。' },

  { id:'enni1', content:'縁日', name:'□□商店街 縁日', client:'□□商店街振興組合', cat:'育成', category:'追加案件',
    off:12, need:6, filled:6, dir:'田中', place:'東京都台東区浅草2-1 □□商店街 一帯', placeShort:'□□商店街', meetPlace:'事務所＋会場現地',
    meet:'10:00', leave:'16:00', enter:'10:30', evStart:'11:00', evEnd:'15:30', format:'イベント東北(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'前泊有', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'fix', status:'確定', yomi:'Bヨミ', mine:false, tags:[], pos:[['MC','ok'],['受付','ok']], added:-12,
    guests:80, teams:4, goods:'山本', transport:'レンタカー', sound:'クラシックプロ小', sales:'baba',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'shinkan', content:'新歓イベント', name:'△△大学 新歓イベント', client:'△△大学', cat:'安定重視', category:'通常案件',
    off:14, need:20, filled:16, dir:'高橋', place:'東京都世田谷区桜上水2-3-4 △△大学 体育館', placeShort:'△△大学 体育館', meetPlace:'会場現地',
    meet:'09:30', leave:'18:00', enter:'10:00', evStart:'10:30', evEnd:'17:30', format:'イベント東(オンライン)', fmt:'online', area:'オンライン',
    scale:'大型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'adj', status:'調整中', yomi:'Aヨミ', mine:true, tags:[], pos:[['D','ok'],['MC','ok'],['受付','short']], added:-9,
    guests:200, teams:10, goods:'佐藤', transport:'電車', sound:'不要', sales:'onuma',
    lineSent:true, handover:true, script:false, opSheet:'作成済', archived:false, draft:false, tentative:false, note:'' },

  { id:'shinkan_yobi', content:'新歓イベント', name:'△△大学 新歓イベント（予備日）', client:'△△大学', cat:'安定重視', category:'通常案件',
    off:16, need:20, filled:2, dir:'高橋', place:'東京都世田谷区桜上水2-3-4 △△大学 体育館', placeShort:'△△大学 体育館', meetPlace:'会場現地',
    meet:'09:30', leave:'18:00', enter:'10:00', evStart:'10:30', evEnd:'17:30', format:'イベント東(オンライン)', fmt:'online', area:'オンライン',
    scale:'大型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'予備日', parentId:'shinkan',
    state:'todo', status:'未着手', yomi:'Aヨミ', mine:true, tags:['予備日'], pos:[['MC','none']], added:-9,
    guests:200, teams:10, goods:'佐藤', transport:'電車', sound:'不要', sales:'onuma',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'konshin', content:'懇親会', name:'◇◇社 懇親会運営', client:'◇◇株式会社', cat:'通常', category:'通常案件',
    off:17, need:8, filled:3, dir:'未定', place:'東京都港区六本木6-1-1 ARENA', placeShort:'ARENA', meetPlace:'会場現地',
    meet:'16:00', leave:'21:00', enter:'17:00', evStart:'18:00', evEnd:'20:30', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'todo', status:'未着手', yomi:'Cヨミ', mine:true, tags:[], pos:[['MC','short'],['受付','ok']], added:-5,
    guests:60, teams:'—', goods:'未定', transport:'ー', sound:'会場音響', sales:'baba',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'hyosho', content:'表彰式', name:'☆☆社 表彰式', client:'☆☆株式会社', cat:'通常', category:'追加案件',
    off:17, need:10, filled:0, dir:'山本', place:'東京都千代田区丸の内1-1 グランドホテル', placeShort:'グランドホテル', meetPlace:'会場現地',
    meet:'15:00', leave:'20:00', enter:'15:30', evStart:'16:00', evEnd:'19:30', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'大型', sd:'田中', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'todo', status:'調整中', yomi:'Aヨミ', mine:false, tags:[], pos:[['MC','none'],['OP','none'],['受付','none']], added:-2,
    guests:150, teams:8, goods:'鈴木', transport:'電車+レンタカー', sound:'会場音響', sales:'baba',
    lineSent:true, handover:false, script:true, opSheet:'作成中', archived:false, draft:false, tentative:false, note:'' },

  { id:'fes_reha', content:'フェス設営リハ', name:'◎◎ フェス設営リハ', client:'◎◎実行委員会', cat:'通常', category:'通常案件',
    off:18, need:8, filled:5, dir:'鈴木', place:'東京都立川市曙町2-1 市民広場', placeShort:'市民広場', meetPlace:'最寄り駅',
    meet:'09:00', leave:'15:00', enter:'—', evStart:'—', evEnd:'—', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'リハ', parentId:'fes_setup',
    state:'adj', status:'調整中', yomi:'確定', mine:true, tags:['リハ'], pos:[['OP','ok']], added:-16,
    guests:'—', teams:'—', goods:'高橋', transport:'電車', sound:'会場音響', sales:'baba',
    lineSent:true, handover:false, script:false, opSheet:'作成中', archived:false, draft:false, tentative:false, note:'' },

  { id:'mizu_yobi', content:'水合戦', name:'〇〇社 水合戦（予備日）', client:'〇〇株式会社', cat:'体力', category:'通常案件',
    off:19, need:8, filled:2, dir:'鈴木', place:'千葉県柏市柏の葉6-1 〇〇公園（屋外）', placeShort:'〇〇公園（屋外）', meetPlace:'会場現地',
    meet:'08:00', leave:'17:00', enter:'09:30', evStart:'10:00', evEnd:'16:00', format:'イベント東(リアルロング)', fmt:'long', area:'千葉',
    scale:'大型', sd:'高橋', lodging:'前泊有', recruit:true, repeat:true, dayType:'予備日', parentId:'mizu',
    state:'todo', status:'未着手', yomi:'確定', mine:true, tags:['予備日','前泊あり'], pos:[['MC','short']], added:-14,
    guests:120, teams:8, goods:'佐藤', transport:'IKUSAカー2台', sound:'クラシックプロ中', sales:'onuma',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  // ── 翌月以降（月またぎ・フォルダで見える例）──
  { id:'bousai', content:'防災フェス', name:'◇◇市 防災フェス', client:'◇◇市役所', cat:'体力', category:'通常案件',
    off:30, need:20, filled:8, dir:'未定', place:'千葉県柏市柏5-10 市役所前広場', placeShort:'市役所前広場', meetPlace:'会場現地',
    meet:'08:00', leave:'16:00', enter:'09:00', evStart:'09:30', evEnd:'15:30', format:'イベント東(リアル)', fmt:'real', area:'千葉',
    scale:'大型', sd:'なし', lodging:'前泊有', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'todo', status:'未着手', yomi:'Bヨミ', mine:true, tags:['前泊あり'], pos:[['MC','short'],['受付','short']], added:-20,
    guests:500, teams:'—', goods:'高橋', transport:'IKUSAカー', sound:'クラシックプロ大', sales:'baba',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'quiz', content:'クイズ大会', name:'大阪◇◇社 クイズ大会', client:'大阪◇◇株式会社', cat:'通常', category:'通常案件',
    off:40, need:6, filled:6, dir:'未定', place:'大阪府大阪市北区梅田3-2-1 △△ホール', placeShort:'大阪 △△ホール', meetPlace:'会場現地',
    meet:'12:00', leave:'18:00', enter:'13:00', evStart:'13:30', evEnd:'17:00', format:'イベント他拠点(ヘルプのみ)', fmt:'online', area:'東京',
    scale:'中型', sd:'なし', lodging:'無', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'todo', status:'未着手', yomi:'Bヨミ', mine:false, tags:[], pos:[['MC','none'],['受付','none']], added:-8,
    guests:100, teams:6, goods:'未定', transport:'電車', sound:'会場音響', sales:'onuma',
    lineSent:false, handover:false, script:false, opSheet:'', archived:false, draft:false, tentative:false, note:'' },

  { id:'fes_setup', content:'フェス設営', name:'◎◎ フェス設営', client:'◎◎実行委員会', cat:'通常', category:'通常案件',
    off:58, need:12, filled:10, dir:'田中', place:'東京都立川市曙町2-1 市民広場', placeShort:'市民広場', meetPlace:'会場現地',
    meet:'07:00', leave:'12:00', enter:'—', evStart:'—', evEnd:'—', format:'イベント東(リアル)', fmt:'real', area:'東京',
    scale:'中型', sd:'なし', lodging:'前泊有', recruit:true, repeat:false, dayType:'本番', parentId:null,
    state:'fix', status:'確定', yomi:'確定', mine:false, tags:['前泊あり'], pos:[['OP','ok']], added:-40,
    guests:'—', teams:'—', goods:'高橋', transport:'IKUSAカー', sound:'不要', sales:'onuma',
    lineSent:true, handover:true, script:true, opSheet:'作成済', archived:false, draft:false, tentative:false, note:'' },

];

/* 便利関数（任意で使う）：今日からの相対日付を Date で返す */
window.ECS_caseDate = function(off){
  var x = new Date(); x.setHours(0,0,0,0); x.setDate(x.getDate() + off); return x;
};

/* =====================================================================
   M-7 危険日（高負荷日）の判定 ＝ 案件登録時・CSV取込時の警告に使う共通関数
   ---------------------------------------------------------------------
   ・スタッフ数 … ECSに登録しているスタッフのうち「アクティブ＋準アクティブ」の人数。
                  変動するため、本番ではDBから自動で数えます。モックでは下の
                  ECS_ACTIVE_STAFF（想定値）を使います。ここの数字を変えれば調整可。
   ・必要スタッフ数 … 各案件の「運営人数 − 1」（案件に必ず1名は社員が入るため）。
   ・危険日（次のいずれかに当てはまる日）：
        ① 大型案件が同じ日に2件以上ある
        ② リアル系案件（オンライン以外）が同じ日に5件以上ある
        ③ その日の「必要スタッフ数」の合計が、スタッフ数の7割以上になる
   ===================================================================== */
window.ECS_ACTIVE_STAFF = 40; // ★アクティブ＋準アクティブの想定人数（モック）。本番はDBで自動算出。

/* 実施形態の文字列 → 種別コード（real / long / online）。
   ⚠ サーバー側の App\Support\ProjectFormats::countCode と同じ規則にそろえること。
     片方だけ変えると、危険日の警告だけ判定が変わる（気づきにくい）。
   ⚠ 2026-09-01 baba訂正：「ヘルプのみ」はオンラインではない。
     ヘルプのみ＝どの拠点が手伝うかという運用の話で、実施形態ではない。
     リアルのイベントは、ヘルプで入っていてもリアル＝危険日にもリアルとして数える。 */
window.ECS_fmtCode = function(formatText){
  var t = String(formatText || '');
  if (t.indexOf('オンライン') >= 0) return 'online';
  if (t.indexOf('リアルロング') >= 0) return 'long';
  return 'real'; // リアル・ARENA・体験会・巻き取り・ヘルプのみ 等はリアル系として扱う
};

/* その日の案件配列 items=[{scale,fmt,need,name}] を渡すと危険判定を返す */
window.ECS_dangerCheck = function(items){
  var staff = window.ECS_ACTIVE_STAFF || 1;
  var big = 0, real = 0, needSum = 0;
  (items || []).forEach(function(it){
    if (it.scale === '大型') big++;
    if (it.fmt === 'real' || it.fmt === 'long') real++;
    var n = parseInt(it.need, 10);
    if (!isNaN(n) && n > 0) needSum += (n - 1); // 必要スタッフ数＝運営人数−1
  });
  var threshold = Math.ceil(staff * 0.7);
  var reasons = [];
  if (big >= 2)            reasons.push('大型案件が' + big + '件重なっています');
  if (real >= 5)           reasons.push('リアル系案件が' + real + '件重なっています');
  if (needSum >= threshold) reasons.push('必要スタッフ数の合計が' + needSum + '名（スタッフ' + staff + '名の7割＝' + threshold + '名以上）');
  return { danger: reasons.length > 0, reasons: reasons, count: (items || []).length,
           big: big, real: real, needSum: needSum, staff: staff, threshold: threshold };
};

/* 既存案件（cases.js）から、指定した日付(YYYY-MM-DD)に開催される案件を返す（過去・下書きは除く） */
window.ECS_casesOnDate = function(iso){
  var out = [];
  (window.ECS_CASES || []).forEach(function(c){
    if (c.archived || c.draft) return;
    var d = window.ECS_caseDate(c.off);
    var ci = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    if (ci === iso) out.push({ scale: c.scale, fmt: c.fmt, need: c.need, name: c.name });
  });
  return out;
};
