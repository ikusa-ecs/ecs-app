/* =========================================================================
   ECS 共通 人名簿（社員・スタッフを1つに統一）  ★R-1
   -------------------------------------------------------------------------
   これまで名簿が staff / staff_status / employees などにバラバラに置かれ、
   同じ人の値が食い違っていた（例：山田涼の通算回数 22/33）。
   この people.js を「唯一の正しい名簿」とし、各画面はここを読む。

   ◆ role …… 'employee'＝社員 / 'staff'＝スタッフ（社員/スタッフの区別フラグ）
   ◆ joinDate …… 入社日／登録日（YYYY-MM-DD）。
        ここから「新人/中堅/ベテラン」を“その場で計算”する（保存しない）。
        新人＝1年未満 / 中堅＝1年以上3年未満 / ベテラン＝3年以上。
        ※スタッフは登録時に手入力する想定。下記の仮日付は「今の区分と
          同じに見える」よう置いた見本（本番は実際の登録日に置き換え）。
   ◆ total …… 通算稼働回数（食い違いは新しい稼働状況側に統一済み）。
   ◆ pos …… スタッフのポジション可否（D/OP/MC/FC/CK/GUN=軍師サポ/UKE=受付）。
   ◆ 稼働状況（active/month/fill/renkin/applied/picked/lastDays/zeroPref）は
        スタッフのみ。cap（月上限）は過重労働防止で全員一律20。
   ◆ 社員は dept（plan=イベプラ/sales=セールス/creative=クリエイティブ）・
        exp（経験コンテンツ）・dexp（Dとしての経験）・wear/shoe を持つ。
   ========================================================================= */

window.ECS_PEOPLE = [

  /* ===================== 社員（employee）===================== */
  { id:'E-001', role:'employee', name:'田中 健一', dept:'plan',     joinDate:'2021-12-01',
    exp:['水合戦','運動会','縁日','懇親会運営','表彰式'], dexp:['水合戦','運動会','表彰式'], wear:'L',  shoe:'27.0' },
  { id:'E-002', role:'employee', name:'鈴木 彩花', dept:'sales',    joinDate:'2023-02-01',
    exp:['縁日','懇親会運営','ワークショップ系','表彰式'], dexp:['縁日','懇親会運営'], wear:'M',  shoe:'24.0' },
  { id:'E-003', role:'employee', name:'佐藤 大輔', dept:'plan',     joinDate:'2024-02-01',
    exp:['運動会','水合戦','クイズ大会'], dexp:['運動会'], wear:'LL', shoe:'28.0' },
  { id:'E-004', role:'employee', name:'高橋 直樹', dept:'creative', joinDate:'2025-04-01',
    exp:['縁日','懇親会運営','表彰式'], dexp:[], wear:'M',  shoe:'26.5' },
  { id:'E-005', role:'employee', name:'山本 萌',   dept:'sales',    joinDate:'2026-01-01',
    exp:['懇親会運営'], dexp:[], wear:'S',  shoe:'23.5' },
  { id:'E-006', role:'employee', name:'中村 蓮',   dept:'plan',     joinDate:'2026-04-01',
    exp:['縁日'], dexp:[], wear:'L',  shoe:'27.5' },

  /* ===================== スタッフ（staff）===================== */
  // --- ベテラン（3年以上） ---
  { id:'S-001', role:'staff', name:'高橋 由依', joinDate:'2019-04-01', exclusive:true,  total:82,
    pos:{D:true, OP:false, MC:true,  FC:true, CK:true, GUN:true,  UKE:true},
    ng:['佐々木 涼'], dnote:'初回から落ち着いて全体を見られる。Dを任せられる。',
    traits:{follow:true, starter:true, atmos:true},
    active:'active', month:13, cap:20, fill:72, renkin:3, zeroPref:false, applied:15, picked:13, lastDays:1 },
  { id:'S-007', role:'staff', name:'伊藤 健', joinDate:'2018-09-01', exclusive:true, total:90,
    pos:{D:true, OP:true, MC:false, FC:true, CK:true, GUN:true, UKE:false},
    ng:[], dnote:'音響まわりに強い。機材トラブルにも冷静。',
    traits:{follow:true, starter:true, atmos:false},
    active:'active', month:14, cap:20, fill:78, renkin:4, zeroPref:false, applied:16, picked:14, lastDays:0 },
  { id:'S-003', role:'staff', name:'渡辺 さくら', joinDate:'2020-05-01', exclusive:true, total:75,
    pos:{D:false, OP:false, MC:true, FC:true, CK:true, GUN:false, UKE:true},
    ng:[], dnote:'盛り上げ系のMCが得意。声がよく通る。',
    traits:{follow:true, starter:true, atmos:true},
    active:'active', month:12, cap:20, fill:60, renkin:3, zeroPref:false, applied:14, picked:12, lastDays:2 },
  { id:'S-027', role:'staff', name:'清水 陽', joinDate:'2021-03-01', exclusive:true, total:70,
    pos:{D:false, OP:false, MC:true, FC:true, CK:true, GUN:true, UKE:true},
    ng:[], dnote:'新人のフォロー役として安定。全ポジションに目が届く。',
    traits:{follow:true, starter:true, atmos:true},
    active:'active', month:11, cap:20, fill:64, renkin:2, zeroPref:false, applied:13, picked:11, lastDays:3 },

  // --- 中堅（1年以上3年未満） ---
  { id:'S-009', role:'staff', name:'松本 美優', joinDate:'2024-04-01', exclusive:false, total:48,
    pos:{D:false, OP:false, MC:false, FC:true, CK:true, GUN:false, UKE:true},
    ng:[], dnote:'現場の空気を明るくする。お客様対応が丁寧。',
    traits:{follow:false, starter:true, atmos:true},
    active:'active', month:9, cap:20, fill:55, renkin:5, zeroPref:false, applied:12, picked:9, lastDays:4 },
  { id:'S-005', role:'staff', name:'井上 大輝', joinDate:'2024-06-01', exclusive:false, total:44,
    pos:{D:false, OP:false, MC:false, FC:true, CK:true, GUN:true, UKE:true},
    ng:[], dnote:'現場経験が豊富で安定して動ける。幅広く対応できる。',
    traits:{follow:true, starter:true, atmos:false},
    active:'active', month:8, cap:20, fill:50, renkin:3, zeroPref:false, applied:11, picked:8, lastDays:5 },
  { id:'S-014', role:'staff', name:'鈴木 美咲', joinDate:'2023-11-01', exclusive:false, total:40,
    pos:{D:false, OP:false, MC:false, FC:true, CK:true, GUN:true, UKE:true},
    ng:[], dnote:'新人フォローが上手。軍師・サポーターを任せられる。',
    traits:{follow:true, starter:true, atmos:true},
    active:'inactive', month:1, cap:20, fill:0, renkin:1, zeroPref:true, applied:5, picked:1, lastDays:41 },
  { id:'S-018', role:'staff', name:'木村 拓海', joinDate:'2024-08-01', exclusive:false, total:36,
    pos:{D:false, OP:true, MC:false, FC:true, CK:true, GUN:false, UKE:false},
    ng:['池田 莉子'], dnote:'チェック業務が正確。PC操作はやや苦手。',
    traits:{follow:false, starter:true, atmos:false},
    active:'semi', month:3, cap:20, fill:18, renkin:2, zeroPref:false, applied:8, picked:3, lastDays:22 },
  { id:'S-021', role:'staff', name:'山田 涼', joinDate:'2024-10-01', exclusive:false, total:33,
    pos:{D:false, OP:false, MC:false, FC:true, CK:true, GUN:false, UKE:true},
    ng:[], dnote:'体力現場の経験が豊富。動きがいい。',
    traits:{follow:false, starter:true, atmos:true},
    active:'semi', month:4, cap:20, fill:22, renkin:2, zeroPref:false, applied:9, picked:4, lastDays:18 },

  // --- 新人（1年未満） ---
  { id:'S-032', role:'staff', name:'佐藤 健太', joinDate:'2026-01-15', exclusive:false, total:6,
    pos:{D:false, OP:false, MC:false, FC:true, CK:false, GUN:false, UKE:true},
    ng:[], dnote:'素直で吸収が早い。まずはFC・受付から経験を積ませたい。',
    traits:{follow:false, starter:false, atmos:true},
    active:'inactive', month:0, cap:20, fill:0, renkin:0, zeroPref:true, applied:7, picked:0, lastDays:60 },
  { id:'S-035', role:'staff', name:'池田 莉子', joinDate:'2026-02-01', exclusive:false, total:4,
    pos:{D:false, OP:false, MC:false, FC:false, CK:true, GUN:false, UKE:true},
    ng:['木村 拓海'], dnote:'受付・チェッカー向き。緊張しやすいのでフォロー役とセットで。',
    traits:{follow:false, starter:false, atmos:false},
    active:'semi', month:2, cap:20, fill:33, renkin:1, zeroPref:false, applied:9, picked:2, lastDays:16 },
  { id:'S-038', role:'staff', name:'橋本 颯', joinDate:'2026-03-01', exclusive:false, total:3,
    pos:{D:false, OP:false, MC:false, FC:true, CK:true, GUN:false, UKE:false},
    ng:[], dnote:'体を動かす現場が得意そう。育成現場で伸ばしたい。',
    traits:{follow:false, starter:true, atmos:true},
    active:'semi', month:2, cap:20, fill:28, renkin:1, zeroPref:false, applied:8, picked:2, lastDays:20 },
  { id:'S-041', role:'staff', name:'石川 葵', joinDate:'2026-04-01', exclusive:false, total:2,
    pos:{D:false, OP:false, MC:false, FC:false, CK:false, GUN:false, UKE:true},
    ng:[], dnote:'まだ受付からスタート。人当たりがよい。',
    traits:{follow:false, starter:false, atmos:true},
    active:'inactive', month:0, cap:20, fill:0, renkin:0, zeroPref:true, applied:4, picked:0, lastDays:75 },
];

/* ===================== 共通ヘルパー（各画面から使う）===================== */

// 区分ラベル
window.ECS_LV_LABEL = { new:'新人', mid:'中堅', vet:'ベテラン' };

// 入社日／登録日からの勤続年数（小数）。ブラウザの実日付基準。
window.ECS_yearsSince = function(joinDate){
  if(!joinDate) return 0;
  var d = new Date(joinDate);
  if(isNaN(d)) return 0;
  return (Date.now() - d.getTime()) / (365.25 * 24 * 60 * 60 * 1000);
};

// 区分を年数から自動判定：新人＝1年未満 / 中堅＝1年以上3年未満 / ベテラン＝3年以上
window.ECS_lvOf = function(person){
  var y = window.ECS_yearsSince(person && person.joinDate);
  return y < 1 ? 'new' : (y < 3 ? 'mid' : 'vet');
};

// ID で1人を引く
window.ECS_personById = function(id){
  return window.ECS_PEOPLE.filter(function(p){ return p.id === id; })[0] || null;
};

// 役割でしぼる
window.ECS_staffList    = function(){ return window.ECS_PEOPLE.filter(function(p){ return p.role === 'staff';    }); };
window.ECS_employeeList = function(){ return window.ECS_PEOPLE.filter(function(p){ return p.role === 'employee'; }); };
