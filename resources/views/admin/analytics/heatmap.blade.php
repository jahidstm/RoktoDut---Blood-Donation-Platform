<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>চাহিদার মানচিত্র — রক্তদূত অ্যাডমিন</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap');

        :root {
            /* Ordinal risk ramp: one hue, light to dark, validated with the dataviz palette checker
               (monotone lightness, visible step gaps, lightest step >= 2:1 on white). */
            --tier-1: #f2938c;  /* elevated */
            --tier-2: #e0524d;  /* warning  */
            --tier-3: #b52a28;  /* high     */
            --tier-4: #7a1517;  /* critical */
            --tier-0: #e6eaf0;  /* no open demand: neutral, not "safe green" */

            --ink-1: #0f172a; --ink-2: #475569; --ink-3: #94a3b8;
            --line: #e2e8f0; --surface: #ffffff; --page: #f4f6f9;
            --accent: #dc2626;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Hind Siliguri', ui-sans-serif, system-ui, sans-serif;
            background: var(--page); color: var(--ink-1); overflow: hidden;
        }

        /* ── Top bar ─────────────────────────────────────── */
        .topbar {
            height: 60px; background: var(--surface); border-bottom: 1px solid var(--line);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 1.25rem; position: relative; z-index: 500;
        }
        .brand { display: flex; align-items: center; gap: .65rem; text-decoration: none; color: inherit; }
        .brand-logo { width: 36px; height: 36px; border-radius: 10px; border: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .brand-name { font-weight: 800; font-size: 1rem; letter-spacing: -.02em; }
        .brand-sub { font-size: .62rem; color: var(--ink-3); font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
        .title { font-weight: 800; font-size: .95rem; display: flex; align-items: center; gap: .5rem; }
        .live { display: inline-flex; align-items: center; gap: .4rem; font-size: .7rem; font-weight: 700; color: var(--accent); background: #fef2f2; border: 1px solid #fecaca; padding: .2rem .7rem; border-radius: 999px; }
        .live i { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); animation: blink 1.6s ease-in-out infinite; }
        @keyframes blink { 50% { opacity: .25; transform: scale(.75); } }
        .top-actions { display: flex; gap: .5rem; align-items: center; }
        .ghost-btn { font-size: .78rem; font-weight: 700; color: var(--ink-2); text-decoration: none; padding: .38rem .8rem; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); transition: border-color .15s, color .15s; }
        .ghost-btn:hover { border-color: var(--ink-1); color: var(--ink-1); }

        /* ── Filter row ──────────────────────────────────── */
        .filters {
            min-height: 54px; background: var(--surface); border-bottom: 1px solid var(--line);
            display: flex; align-items: center; gap: .9rem; padding: .45rem 1.25rem; overflow-x: auto;
        }
        .seg { display: inline-flex; background: #f1f5f9; border-radius: 11px; padding: 3px; gap: 2px; flex-shrink: 0; }
        .seg button {
            border: 0; background: transparent; font: inherit; font-size: .74rem; font-weight: 700;
            color: var(--ink-2); padding: .34rem .75rem; border-radius: 8px; cursor: pointer; white-space: nowrap;
            transition: background .2s, color .2s, box-shadow .2s;
        }
        .seg button:hover { color: var(--ink-1); }
        .seg button.on { background: var(--surface); color: var(--ink-1); box-shadow: 0 1px 3px rgba(15,23,42,.12); }
        .seg.red button.on { background: var(--accent); color: #fff; }
        .f-label { font-size: .62rem; font-weight: 800; color: var(--ink-3); letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        .f-sep { width: 1px; height: 26px; background: var(--line); flex-shrink: 0; }

        .demo-banner {
            background: #fffbeb; border-bottom: 1px solid #fcd34d; color: #92400e;
            font-size: .78rem; font-weight: 600; padding: .5rem 1.25rem;
        }

        /* ── Layout ──────────────────────────────────────── */
        .wrap {
            display: grid; grid-template-columns: 300px 1fr 330px;
            height: calc(100vh - 114px);
        }
        .wrap.has-banner { height: calc(100vh - 148px); }
        .panel { background: var(--surface); overflow-y: auto; padding: 1rem; }
        .panel.left { border-right: 1px solid var(--line); }
        .panel.right { border-left: 1px solid var(--line); display: flex; flex-direction: column; }
        .panel::-webkit-scrollbar { width: 4px; }
        .panel::-webkit-scrollbar-thumb { background: var(--line); border-radius: 4px; }
        .sec { margin-bottom: 1.3rem; }
        .sec-title { font-size: .62rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-3); margin: 0 0 .6rem; }

        /* KPI tiles */
        .kpis { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }
        .kpi { border: 1px solid var(--line); border-radius: 14px; padding: .75rem .8rem; background: linear-gradient(180deg, #fff, #fafbfc); }
        .kpi.wide { grid-column: span 2; }
        .kpi-l { font-size: .64rem; font-weight: 700; color: var(--ink-3); }
        .kpi-v { font-size: 1.7rem; font-weight: 800; letter-spacing: -.03em; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .kpi-s { font-size: .64rem; color: var(--ink-3); margin-top: .1rem; }

        /* Bars (blood groups + ranking) */
        .bar-row { display: grid; grid-template-columns: 38px 1fr 34px; align-items: center; gap: .5rem; margin-bottom: .38rem; }
        .bar-name { font-size: .74rem; font-weight: 800; color: var(--ink-1); }
        .bar-track { height: 9px; background: #f1f5f9; border-radius: 5px; overflow: hidden; }
        .bar-fill { height: 100%; width: 0; background: var(--accent); border-radius: 0 4px 4px 0; transition: width .9s cubic-bezier(.22,1,.36,1); }
        .bar-num { font-size: .72rem; font-weight: 700; color: var(--ink-2); text-align: right; font-variant-numeric: tabular-nums; }

        .rank { list-style: none; margin: 0; padding: 0; }
        .rank li {
            display: grid; grid-template-columns: 22px 1fr auto; gap: .55rem; align-items: center;
            padding: .5rem .55rem; border-radius: 11px; cursor: pointer;
            transition: background .15s, transform .15s;
            opacity: 0; transform: translateY(6px); animation: rowIn .45s cubic-bezier(.22,1,.36,1) forwards;
        }
        .rank li:hover, .rank li.sel { background: #f8fafc; }
        .rank li.sel { box-shadow: inset 3px 0 0 var(--accent); }
        @keyframes rowIn { to { opacity: 1; transform: none; } }
        .rank-n { font-size: .68rem; font-weight: 800; color: var(--ink-3); text-align: center; }
        .rank-name { font-size: .8rem; font-weight: 700; }
        .rank-meta { font-size: .64rem; color: var(--ink-3); }
        .rank-bar { height: 5px; border-radius: 3px; background: #f1f5f9; margin-top: .25rem; overflow: hidden; }
        .rank-bar span { display: block; height: 100%; width: 0; border-radius: 0 3px 3px 0; transition: width .9s cubic-bezier(.22,1,.36,1); }
        .tier-chip { font-size: .62rem; font-weight: 800; padding: .14rem .5rem; border-radius: 999px; white-space: nowrap; border: 1px solid; }

        .legend-row { display: flex; align-items: center; gap: .55rem; font-size: .74rem; color: var(--ink-2); margin-bottom: .3rem; }
        .sw { width: 22px; height: 12px; border-radius: 4px; flex-shrink: 0; }
        .note { font-size: .68rem; color: var(--ink-2); line-height: 1.6; background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: .6rem .7rem; }
        .note.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }

        .export-btn {
            display: flex; justify-content: center; align-items: center; gap: .45rem;
            padding: .75rem; border-radius: 11px; background: var(--ink-1); color: #fff;
            font-size: .78rem; font-weight: 700; text-decoration: none; transition: background .15s;
        }
        .export-btn:hover { background: #1e293b; }

        /* ── Map ─────────────────────────────────────────── */
        .map-col { position: relative; padding: 12px; }
        #map {
            width: 100%; height: 100%; border-radius: 20px; border: 1px solid var(--line);
            background: radial-gradient(ellipse at 50% 40%, #ffffff 0%, #eef2f7 70%);
            box-shadow: 0 18px 40px rgba(15,23,42,.08);
        }
        .leaflet-container { font-family: inherit; }
        .leaflet-control-attribution { display: none !important; }
        .leaflet-control-zoom { border: 0 !important; box-shadow: 0 4px 14px rgba(15,23,42,.12) !important; border-radius: 12px !important; overflow: hidden; }
        .leaflet-control-zoom a { color: var(--ink-2) !important; border-color: var(--line) !important; }

        /* District shapes: inline style changes transition, so filter switches morph instead of blink. */
        path.district {
            transition: fill .7s ease, fill-opacity .6s ease, stroke-width .2s ease, stroke .2s ease;
            cursor: pointer;
        }
        path.district.dim { fill-opacity: .25 !important; }
        .map-col.bubbles path.district { fill: var(--tier-0) !important; }

        /* Pulsing rings on high and critical districts */
        .pulse { position: relative; width: 14px; height: 14px; pointer-events: none; }
        .pulse::before, .pulse::after {
            content: ''; position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid var(--c); animation: ring 2.2s cubic-bezier(.2,.6,.4,1) infinite;
        }
        .pulse::after { animation-delay: 1.1s; }
        .pulse i { position: absolute; inset: 4px; border-radius: 50%; background: var(--c); box-shadow: 0 0 0 2px #fff; }
        @keyframes ring { from { transform: scale(.6); opacity: .9; } to { transform: scale(3.2); opacity: 0; } }

        /* Floating cards on the map */
        .float { position: absolute; z-index: 450; background: rgba(255,255,255,.94); backdrop-filter: blur(8px);
                 border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 12px 30px rgba(15,23,42,.12); }
        .map-legend { left: 26px; bottom: 26px; padding: .7rem .85rem; }
        .map-legend .legend-row { margin-bottom: .2rem; font-size: .7rem; }
        .map-reset { right: 26px; top: 26px; padding: .45rem .8rem; font: inherit; font-size: .74rem; font-weight: 700; color: var(--ink-2); cursor: pointer; display: none; }
        .map-reset.show { display: block; animation: pop .25s ease both; }

        .detail { right: 26px; bottom: 26px; width: 270px; padding: 1rem 1.05rem; display: none; }
        .detail.show { display: block; animation: pop .3s cubic-bezier(.22,1,.36,1) both; }
        @keyframes pop { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }
        .detail h3 { margin: 0; font-size: 1.05rem; font-weight: 800; }
        .detail .en { font-size: .66rem; color: var(--ink-3); }
        .detail .grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: .4rem; margin: .7rem 0; }
        .detail .cell { background: #f8fafc; border-radius: 10px; padding: .45rem .5rem; }
        .detail .cell b { display: block; font-size: 1.05rem; font-weight: 800; font-variant-numeric: tabular-nums; }
        .detail .cell span { font-size: .6rem; color: var(--ink-3); font-weight: 700; }
        .detail-close { position: absolute; top: .55rem; right: .7rem; border: 0; background: none; font-size: 1.1rem; color: var(--ink-3); cursor: pointer; }

        /* Tooltip */
        .leaflet-tooltip.tt { background: transparent; border: 0; box-shadow: none; padding: 0; }
        .leaflet-tooltip.tt::before { display: none; }
        .tt-card { background: #fff; border: 1px solid var(--line); border-radius: 13px; box-shadow: 0 10px 28px rgba(15,23,42,.16);
                   padding: .65rem .8rem; min-width: 190px; animation: pop .16s ease both; }
        .tt-name { font-weight: 800; font-size: .86rem; margin-bottom: .35rem; }
        .tt-row { display: flex; justify-content: space-between; gap: 1rem; font-size: .72rem; color: var(--ink-2); }
        .tt-row b { color: var(--ink-1); font-variant-numeric: tabular-nums; }

        /* Loading */
        #loading { position: absolute; inset: 12px; z-index: 600; border-radius: 20px; background: rgba(244,246,249,.85);
                   display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .6rem; backdrop-filter: blur(3px);
                   transition: opacity .3s; }
        #loading.hide { opacity: 0; pointer-events: none; }
        .spinner { width: 34px; height: 34px; border-radius: 50%; border: 3px solid #fecaca; border-top-color: var(--accent); animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; transition-duration: .001ms !important; }
            .pulse::before, .pulse::after { display: none; }
        }

        @media (max-width: 1000px) {
            html, body { overflow: auto; height: auto; }
            .title, .live { display: none; }
            .wrap, .wrap.has-banner { display: flex; flex-direction: column; height: auto; }
            .map-col { order: -1; height: 64vh; min-height: 420px; }
            .panel.left, .panel.right { border: 0; border-top: 1px solid var(--line); }
            .detail { left: 26px; right: 26px; width: auto; }
            .map-legend { display: none; }
        }
    </style>
</head>
<body>

<header class="topbar" x-data="{ mobileMenuOpen: false }">
    <a href="{{ route('admin.dashboard') }}" class="brand">
        <div class="brand-logo"><x-logo size="sm" variant="icon" /></div>
        <div>
            <div class="brand-name">রক্তদূত</div>
            <div class="brand-sub">Admin</div>
        </div>
    </a>
    <div class="title">জেলাভিত্তিক রক্তের চাহিদার মানচিত্র <span class="live"><i></i>প্রতি ৩ মিনিটে হালনাগাদ</span></div>
    <div class="top-actions">
        <a href="{{ route('live-demand.index') }}" target="_blank" class="ghost-btn">পাবলিক ভিউ ↗</a>
        <a href="{{ route('admin.analytics.index') }}" class="ghost-btn">← অ্যানালিটিক্স</a>
    </div>
    @include('components.mobile-menu')
</header>

<div class="filters">
    <span class="f-label">ডেটা</span>
    <div class="seg" id="f-source">
        <button data-v="real" class="{{ $source === 'real' ? 'on' : '' }}">আসল</button>
        <button data-v="demo" class="{{ $source === 'demo' ? 'on' : '' }}">ডেমো</button>
    </div>
    <div class="f-sep"></div>
    <span class="f-label">সময়</span>
    <div class="seg" id="f-range">
        @foreach (['all_time' => 'সব সময়', 'today' => 'আজ', 'last_7_days' => '৭ দিন', 'last_30_days' => '৩০ দিন'] as $k => $label)
            <button data-v="{{ $k }}" class="{{ $dateRange === $k ? 'on' : '' }}">{{ $label }}</button>
        @endforeach
    </div>
    <div class="f-sep"></div>
    <span class="f-label">গ্রুপ</span>
    <div class="seg red" id="f-group">
        <button data-v="" class="{{ empty($bloodGroup) ? 'on' : '' }}">সব</button>
        @foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $g)
            <button data-v="{{ $g }}" class="{{ $bloodGroup === $g ? 'on' : '' }}">{{ $g }}</button>
        @endforeach
    </div>
    <div class="f-sep"></div>
    <span class="f-label">দেখাও</span>
    <div class="seg" id="f-mode">
        <button data-v="fill" class="on">রঙে ঝুঁকি</button>
        <button data-v="bubbles">বৃত্তে চাহিদা</button>
    </div>
</div>

<div class="demo-banner" id="demo-banner" @if ($source !== 'demo') hidden @endif>
    ⚠ <strong>ডেমো ডেটা দেখানো হচ্ছে।</strong> এই রিকোয়েস্টগুলো কম্পিউটারে বানানো, আসল কোনো রোগীর নয়।
    @if ($autoDemo) আসল রিকোয়েস্ট খুব কম থাকায় ডেমো নিজে থেকে বেছে নেওয়া হয়েছে। @endif
</div>

<div class="wrap {{ $source === 'demo' ? 'has-banner' : '' }}" id="wrap">

    {{-- LEFT --}}
    <aside class="panel left">
        <div class="sec">
            <p class="sec-title">এক নজরে</p>
            <div class="kpis">
                <div class="kpi wide">
                    <div class="kpi-l">খোলা রিকোয়েস্ট</div>
                    <div class="kpi-v" id="k-total" style="color:var(--accent)">০</div>
                    <div class="kpi-s" id="k-range"></div>
                </div>
                <div class="kpi">
                    <div class="kpi-l">চাহিদা আছে এমন জেলা</div>
                    <div class="kpi-v" id="k-active">০</div>
                    <div class="kpi-s">৬৪টির মধ্যে</div>
                </div>
                <div class="kpi">
                    <div class="kpi-l">উচ্চ ঝুঁকির জেলা</div>
                    <div class="kpi-v" id="k-high" style="color:var(--tier-3)">০</div>
                    <div class="kpi-s">স্কোর ৫০-এর বেশি</div>
                </div>
            </div>
        </div>

        <div class="sec">
            <p class="sec-title">রক্তের গ্রুপ অনুযায়ী চাহিদা</p>
            <div id="groups"></div>
        </div>

        <div class="sec">
            <p class="sec-title">স্কোর কীভাবে হয়</p>
            <div class="note">
                <b>ঝুঁকি স্কোর (০–১০০)</b> = জেলার চাহিদা (সর্বোচ্চ জেলার তুলনায়) × ০.৬ + ডোনারের ক্লান্তির সূচক × ০.৪।
                ওজনগুলো হাতে ঠিক করা, তাই এটা একটা <b>হিউরিস্টিক সূচক</b>, প্রশিক্ষিত মডেল নয়।
            </div>
            <div class="note warn" id="dfi-warning" style="margin-top:.5rem" hidden>
                ডোনারের ক্লান্তির ডেটা (Redis) পাওয়া যায়নি, তাই স্কোর এখন শুধু চাহিদা থেকে আসছে এবং ৬০-এর বেশি হতে পারে না।
            </div>
        </div>
    </aside>

    {{-- MAP --}}
    <main class="map-col" id="map-col">
        <div id="loading"><div class="spinner"></div><span style="font-size:.78rem;color:var(--ink-2)">মানচিত্র লোড হচ্ছে…</span></div>
        <div id="map" role="img" aria-label="জেলাভিত্তিক রক্তের চাহিদার মানচিত্র। একই তথ্য ডান পাশের তালিকায় আছে।"></div>

        <div class="float map-legend" id="legend"></div>
        <button class="float map-reset" id="reset">↺ পুরো দেশ</button>

        <div class="float detail" id="detail" aria-live="polite">
            <button class="detail-close" id="detail-close" aria-label="বন্ধ করুন">×</button>
            <div id="detail-body"></div>
        </div>
    </main>

    {{-- RIGHT --}}
    <aside class="panel right">
        <p class="sec-title">ঝুঁকি অনুযায়ী জেলা</p>
        <ul class="rank" id="rank" style="flex:1"></ul>
        <div style="padding-top:.75rem">
            <a id="export" class="export-btn" href="#">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                CSV ডাউনলোড (৬৪ জেলা)
            </a>
            <p style="font-size:.62rem;color:var(--ink-3);text-align:center;margin:.4rem 0 0" id="updated">তৈরি: {{ $generatedAt }}</p>
        </div>
    </aside>
</div>

<script>
(function () {
    const EN_TO_BN = {
        'Comilla':'কুমিল্লা','Feni':'ফেনী','Brahamanbaria':'ব্রাহ্মণবাড়িয়া','Rangamati':'রাঙ্গামাটি',
        'Noakhali':'নোয়াখালী','Chandpur':'চাঁদপুর','Lakshmipur':'লক্ষ্মীপুর','Chittagong':'চট্টগ্রাম',
        "Cox'SBazar":'কক্সবাজার','Khagrachhari':'খাগড়াছড়ি','Bandarban':'বান্দরবান','Sirajganj':'সিরাজগঞ্জ',
        'Pabna':'পাবনা','Bogra':'বগুড়া','Rajshahi':'রাজশাহী','Natore':'নাটোর','Joypurhat':'জয়পুরহাট',
        'Nawabganj':'চাঁপাইনবাবগঞ্জ','Naogaon':'নওগাঁ','Jessore':'যশোর','Satkhira':'সাতক্ষীরা',
        'Meherpur':'মেহেরপুর','Narail':'নড়াইল','Chuadanga':'চুয়াডাঙ্গা','Kushtia':'কুষ্টিয়া',
        'Magura':'মাগুরা','Khulna':'খুলনা','Bagerhat':'বাগেরহাট','Jhenaidah':'ঝিনাইদহ',
        'Jhalokati':'ঝালকাঠি','Patuakhali':'পটুয়াখালী','Pirojpur':'পিরোজপুর','Barisal':'বরিশাল',
        'Bhola':'ভোলা','Barguna':'বরগুনা','Sylhet':'সিলেট','Maulvibazar':'মৌলভীবাজার',
        'Habiganj':'হবিগঞ্জ','Sunamganj':'সুনামগঞ্জ','Narsingdi':'নরসিংদী','Gazipur':'গাজীপুর',
        'Shariatpur':'শরীয়তপুর','Narayanganj':'নারায়ণগঞ্জ','Tangail':'টাঙ্গাইল','Kishoreganj':'কিশোরগঞ্জ',
        'Manikganj':'মানিকগঞ্জ','Dhaka':'ঢাকা','Munshiganj':'মুন্সিগঞ্জ','Rajbari':'রাজবাড়ী',
        'Madaripur':'মাদারীপুর','Gopalganj':'গোপালগঞ্জ','Faridpur':'ফরিদপুর','Panchagarh':'পঞ্চগড়',
        'Dinajpur':'দিনাজপুর','Lalmonirhat':'লালমনিরহাট','Nilphamari':'নীলফামারী','Gaibandha':'গাইবান্ধা',
        'Thakurgaon':'ঠাকুরগাঁও','Rangpur':'রংপুর','Kurigram':'কুড়িগ্রাম','Sherpur':'শেরপুর',
        'Mymensingh':'ময়মনসিংহ','Jamalpur':'জামালপুর','Netrakona':'নেত্রকোণা',
    };
    const bn = n => EN_TO_BN[n] || n;
    const D = '০১২৩৪৫৬৭৮৯';
    const bnNum = v => String(v).replace(/\d/g, d => D[d]);
    const fmt = v => bnNum(Number.isInteger(v) ? v : (Math.round(v * 10) / 10));
    const REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;

    const css = getComputedStyle(document.documentElement);
    const TIERS = [
        { min: 75, key: 'critical', label: 'সংকট', range: '৭৫-এর বেশি', color: css.getPropertyValue('--tier-4').trim() },
        { min: 50, key: 'high',     label: 'উচ্চ',   range: '৫১–৭৫',     color: css.getPropertyValue('--tier-3').trim() },
        { min: 30, key: 'warning',  label: 'সতর্কতা', range: '৩১–৫০',     color: css.getPropertyValue('--tier-2').trim() },
        { min: 0,  key: 'elevated', label: 'মনোযোগ', range: '১–৩০',      color: css.getPropertyValue('--tier-1').trim() },
    ];
    const NONE = { key: 'none', label: 'খোলা চাহিদা নেই', range: '০', color: css.getPropertyValue('--tier-0').trim() };
    const tierOf = (crs, demand) => (!demand || crs <= 0) ? NONE : TIERS.find(t => crs > t.min) || TIERS[3];
    const RANGE_BN = { all_time: 'সব সময়ের খোলা রিকোয়েস্ট', today: 'আজকে তৈরি', last_7_days: 'গত ৭ দিনে তৈরি', last_30_days: 'গত ৩০ দিনে তৈরি' };

    const state = {
        source: @json($source),
        range: @json($dateRange),
        group: @json($bloodGroup ?? ''),
        mode: 'fill',
        selected: null,
        data: {},
    };

    // ── Map ──────────────────────────────────────────────────
    const map = L.map('map', { zoomSnap: 0.25, zoomDelta: 0.5, attributionControl: false, zoomControl: true });
    map.zoomControl.setPosition('topleft');
    const ringLayer = L.layerGroup().addTo(map);
    const bubbleLayer = L.layerGroup();
    const layers = {};   // EN name -> Leaflet path
    let geoLayer, homeBounds;

    // Count-up for the KPI numbers
    function countUp(el, to) {
        const from = Number(el.dataset.v || 0);
        el.dataset.v = to;
        if (REDUCED || from === to) { el.textContent = bnNum(to); return; }
        const t0 = performance.now(), dur = 800;
        (function tick(t) {
            const p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
            el.textContent = bnNum(Math.round(from + (to - from) * e));
            if (p < 1) requestAnimationFrame(tick);
        })(t0);
    }

    function tooltipHtml(en, info) {
        const t = tierOf(info.crs, info.demand);
        return `<div class="tt-card">
            <div class="tt-name">${bn(en)}</div>
            <div class="tt-row"><span>খোলা রিকোয়েস্ট</span><b>${fmt(info.demand)}টি</b></div>
            <div class="tt-row"><span>ঝুঁকি স্কোর</span><b>${fmt(info.crs)} · ${t.label}</b></div>
            ${info.top_blood_group ? `<div class="tt-row"><span>সবচেয়ে বেশি চাওয়া</span><b>${info.top_blood_group}</b></div>` : ''}
            <div class="tt-row" style="margin-top:.3rem;color:var(--ink-3)"><span>বিস্তারিত দেখতে ক্লিক করুন</span></div>
        </div>`;
    }

    function paint(first) {
        const entries = Object.entries(layers);
        // A west-to-east wave on first load; afterwards colours just morph in place.
        const order = first ? entries.slice().sort((a, b) => a[1].getBounds().getCenter().lng - b[1].getBounds().getCenter().lng) : entries;

        order.forEach(([en, layer], i) => {
            const info = state.data[en] || { demand: 0, crs: 0, avg_dfi: 0, blood_groups: {} };
            const el = layer.getElement();
            if (!el) return;
            const color = tierOf(info.crs, info.demand).color;
            const apply = () => { el.style.fill = color; el.style.fillOpacity = 0.92; };
            if (first && !REDUCED) { el.style.fillOpacity = 0; setTimeout(apply, 120 + i * 14); } else { apply(); }
            layer.setTooltipContent(tooltipHtml(en, info));
            el.classList.toggle('dim', !!state.selected && state.selected !== en);
        });

        drawRings();
        drawBubbles();
    }

    function drawRings() {
        ringLayer.clearLayers();
        Object.entries(state.data).forEach(([en, info]) => {
            if (!layers[en] || info.crs <= 50 || !info.demand) return;
            const c = tierOf(info.crs, info.demand).color;
            L.marker(layers[en].getBounds().getCenter(), {
                interactive: false, keyboard: false,
                icon: L.divIcon({ className: '', iconSize: [14, 14], html: `<div class="pulse" style="--c:${c}"><i></i></div>` }),
            }).addTo(ringLayer);
        });
    }

    function drawBubbles() {
        bubbleLayer.clearLayers();
        const max = Math.max(1, ...Object.values(state.data).map(d => d.demand));
        Object.entries(state.data).forEach(([en, info]) => {
            if (!layers[en] || !info.demand) return;
            // Area, not radius, proportional to demand.
            const target = 5 + 26 * Math.sqrt(info.demand / max);
            const m = L.circleMarker(layers[en].getBounds().getCenter(), {
                radius: REDUCED ? target : 0, color: '#ffffff', weight: 2,
                fillColor: tierOf(info.crs, info.demand).color, fillOpacity: 0.8,
            }).bindTooltip(tooltipHtml(en, info), { sticky: true, className: 'tt', direction: 'top', offset: [0, -6] })
              .on('click', () => select(en))
              .addTo(bubbleLayer);
            if (!REDUCED && state.mode === 'bubbles') grow(m, target);
            else m.setRadius(target);
        });
    }

    function grow(marker, target) {
        const t0 = performance.now();
        (function tick(t) {
            const p = Math.min(1, (t - t0) / 700), e = 1 - Math.pow(1 - p, 3);
            marker.setRadius(target * e);
            if (p < 1) requestAnimationFrame(tick);
        })(t0);
    }

    // ── Panels ───────────────────────────────────────────────
    function renderPanels() {
        const rows = Object.entries(state.data).filter(([, d]) => d.demand > 0);
        const total = rows.reduce((s, [, d]) => s + d.demand, 0);
        countUp(document.getElementById('k-total'), total);
        countUp(document.getElementById('k-active'), rows.length);
        countUp(document.getElementById('k-high'), rows.filter(([, d]) => d.crs > 50).length);
        document.getElementById('k-range').textContent = RANGE_BN[state.range] + (state.group ? `, শুধু ${state.group}` : '');

        // National blood-group totals
        const groups = {};
        rows.forEach(([, d]) => Object.entries(d.blood_groups || {}).forEach(([g, n]) => groups[g] = (groups[g] || 0) + n));
        const gMax = Math.max(1, ...Object.values(groups));
        const gEl = document.getElementById('groups');
        const sortedGroups = Object.entries(groups).sort((a, b) => b[1] - a[1]);
        gEl.innerHTML = sortedGroups.length ? sortedGroups.map(([g, n]) => `
            <div class="bar-row"><span class="bar-name">${g}</span>
                <div class="bar-track"><div class="bar-fill" data-w="${(n / gMax) * 100}"></div></div>
                <span class="bar-num">${fmt(n)}</span></div>`).join('')
            : '<p style="font-size:.74rem;color:var(--ink-3);margin:0">এই ফিল্টারে কোনো খোলা রিকোয়েস্ট নেই।</p>';

        // Ranked district list (doubles as the table view of the map)
        const maxCrs = Math.max(1, ...rows.map(([, d]) => d.crs));
        rows.sort((a, b) => b[1].crs - a[1].crs || b[1].demand - a[1].demand);
        document.getElementById('rank').innerHTML = rows.length ? rows.map(([en, d], i) => {
            const t = tierOf(d.crs, d.demand);
            return `<li data-en="${en}" class="${state.selected === en ? 'sel' : ''}" style="animation-delay:${REDUCED ? 0 : Math.min(i, 20) * 35}ms">
                <span class="rank-n">${bnNum(i + 1)}</span>
                <div><div class="rank-name">${bn(en)}</div>
                    <div class="rank-meta">${fmt(d.demand)}টি রিকোয়েস্ট${d.top_blood_group ? ' · বেশি ' + d.top_blood_group : ''}</div>
                    <div class="rank-bar"><span data-w="${(d.crs / maxCrs) * 100}" style="background:${t.color}"></span></div></div>
                <span class="tier-chip" style="color:${t.key === 'elevated' ? '#9a3412' : t.color};border-color:${t.color}">${fmt(d.crs)}</span>
            </li>`;
        }).join('') : '<li style="opacity:1;transform:none;cursor:default;display:block;font-size:.76rem;color:var(--ink-3)">এই ফিল্টারে কোনো জেলায় খোলা রিকোয়েস্ট নেই।</li>';

        // Let the bars grow from zero
        requestAnimationFrame(() => requestAnimationFrame(() => {
            document.querySelectorAll('[data-w]').forEach(b => b.style.width = b.dataset.w + '%');
        }));

        const noDfi = rows.length > 0 && rows.every(([, d]) => !d.avg_dfi);
        document.getElementById('dfi-warning').hidden = !noDfi;

        const q = new URLSearchParams({ range: state.range, source: state.source });
        if (state.group) q.set('group', state.group);
        document.getElementById('export').href = `{{ route('admin.heatmap.export') }}?${q}`;
        document.getElementById('updated').textContent = 'শেষ হালনাগাদ: ' + bnNum(new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }));

        if (state.selected) showDetail(state.selected);
    }

    function renderLegend() {
        document.getElementById('legend').innerHTML =
            `<div style="font-size:.62rem;font-weight:800;color:var(--ink-3);letter-spacing:.08em;margin-bottom:.35rem">ঝুঁকি স্কোর</div>` +
            [...TIERS, NONE].map(t => `<div class="legend-row"><span class="sw" style="background:${t.color}"></span>${t.label} <span style="color:var(--ink-3)">(${t.range})</span></div>`).join('') +
            `<div class="legend-row" style="margin-top:.3rem"><span class="pulse" style="--c:${TIERS[1].color};width:12px;height:12px;margin:0 5px"><i></i></span>স্কোর ৫০-এর বেশি</div>`;
    }

    // ── Selection ────────────────────────────────────────────
    function showDetail(en) {
        const d = state.data[en] || { demand: 0, crs: 0, avg_dfi: 0, blood_groups: {} };
        const t = tierOf(d.crs, d.demand);
        const groups = Object.entries(d.blood_groups || {}).sort((a, b) => b[1] - a[1]);
        const gMax = Math.max(1, ...groups.map(g => g[1]));
        document.getElementById('detail-body').innerHTML = `
            <h3>${bn(en)}</h3><div class="en">${en}</div>
            <span class="tier-chip" style="display:inline-block;margin-top:.45rem;color:${t.key === 'elevated' ? '#9a3412' : (t.key === 'none' ? 'var(--ink-2)' : t.color)};border-color:${t.key === 'none' ? 'var(--line)' : t.color}">${t.label}</span>
            <div class="grid3">
                <div class="cell"><b>${fmt(d.demand)}</b><span>রিকোয়েস্ট</span></div>
                <div class="cell"><b>${fmt(d.crs)}</b><span>ঝুঁকি স্কোর</span></div>
                <div class="cell"><b>${fmt(d.avg_dfi)}</b><span>ক্লান্তি সূচক</span></div>
            </div>
            ${groups.length ? groups.map(([g, n]) => `<div class="bar-row"><span class="bar-name">${g}</span>
                <div class="bar-track"><div class="bar-fill" data-w="${(n / gMax) * 100}"></div></div><span class="bar-num">${fmt(n)}</span></div>`).join('')
              : '<p style="font-size:.74rem;color:var(--ink-3);margin:0">এই জেলায় এখন কোনো খোলা রিকোয়েস্ট নেই।</p>'}`;
        document.getElementById('detail').classList.add('show');
        requestAnimationFrame(() => requestAnimationFrame(() =>
            document.querySelectorAll('#detail [data-w]').forEach(b => b.style.width = b.dataset.w + '%')));
    }

    function select(en) {
        state.selected = en;
        Object.entries(layers).forEach(([name, l]) => l.getElement()?.classList.toggle('dim', name !== en));
        document.querySelectorAll('#rank li').forEach(li => li.classList.toggle('sel', li.dataset.en === en));
        showDetail(en);
        map.flyToBounds(layers[en].getBounds(), { padding: [80, 80], maxZoom: 9, duration: REDUCED ? 0 : 0.9 });
        document.getElementById('reset').classList.add('show');
    }

    function clearSelection() {
        state.selected = null;
        Object.values(layers).forEach(l => l.getElement()?.classList.remove('dim'));
        document.querySelectorAll('#rank li').forEach(li => li.classList.remove('sel'));
        document.getElementById('detail').classList.remove('show');
        document.getElementById('reset').classList.remove('show');
        if (homeBounds) map.flyToBounds(homeBounds, { duration: REDUCED ? 0 : 0.8 });
    }

    // ── Data ─────────────────────────────────────────────────
    async function load(first) {
        const loading = document.getElementById('loading');
        if (!first) loading.classList.remove('hide');
        try {
            const q = new URLSearchParams({ range: state.range, source: state.source });
            if (state.group) q.set('group', state.group);
            const res = await fetch(`{{ route('admin.heatmap.data') }}?${q}`, { headers: { Accept: 'application/json' } });
            state.data = await res.json();
            paint(first);
            renderPanels();
        } catch (e) {
            console.error('[heatmap] load failed', e);
        } finally {
            loading.classList.add('hide');
        }
    }

    function bindSeg(id, key, after) {
        document.getElementById(id).addEventListener('click', e => {
            const b = e.target.closest('button');
            if (!b || state[key] === b.dataset.v) return;
            state[key] = b.dataset.v;
            document.querySelectorAll(`#${id} button`).forEach(x => x.classList.toggle('on', x === b));
            after();
        });
    }

    bindSeg('f-range', 'range', () => load(false));
    bindSeg('f-group', 'group', () => load(false));
    bindSeg('f-source', 'source', () => {
        const demo = state.source === 'demo';
        document.getElementById('demo-banner').hidden = !demo;
        document.getElementById('wrap').classList.toggle('has-banner', demo);
        setTimeout(() => map.invalidateSize(), 50);
        load(false);
    });
    bindSeg('f-mode', 'mode', () => {
        const bubbles = state.mode === 'bubbles';
        document.getElementById('map-col').classList.toggle('bubbles', bubbles);
        if (bubbles) { map.removeLayer(ringLayer); bubbleLayer.addTo(map); drawBubbles(); }
        else { map.removeLayer(bubbleLayer); ringLayer.addTo(map); }
    });

    document.getElementById('rank').addEventListener('click', e => {
        const li = e.target.closest('li[data-en]');
        if (li) select(li.dataset.en);
    });
    document.getElementById('reset').addEventListener('click', clearSelection);
    document.getElementById('detail-close').addEventListener('click', clearSelection);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && state.selected) clearSelection(); });

    // ── Boot ─────────────────────────────────────────────────
    renderLegend();
    fetch('/geojson/bangladesh.geojson').then(r => r.json()).then(geo => {
        geoLayer = L.geoJSON(geo, {
            style: () => ({ className: 'district', color: '#ffffff', weight: 1.2, fillColor: NONE.color, fillOpacity: 0.92 }),
            onEachFeature(feature, layer) {
                const en = feature.properties?.NAME_2 || '';
                layers[en] = layer;
                layer.bindTooltip('', { sticky: true, className: 'tt', direction: 'top', offset: [0, -8] });
                layer.on({
                    mouseover: e => { e.target.setStyle({ weight: 2.6, color: '#0f172a' }); e.target.bringToFront(); },
                    mouseout:  e => { e.target.setStyle({ weight: 1.2, color: '#ffffff' }); },
                    click: () => select(en),
                });
            },
        }).addTo(map);

        homeBounds = geoLayer.getBounds();
        map.fitBounds(homeBounds, { padding: [10, 10] });
        map.setMinZoom(map.getZoom() - 0.5);
        map.setMaxBounds(homeBounds.pad(0.15));
        window.addEventListener('resize', () => { map.invalidateSize(); if (!state.selected) map.fitBounds(homeBounds, { padding: [10, 10] }); });

        load(true);
        setInterval(() => load(false), 3 * 60 * 1000);
    }).catch(e => {
        console.error('[heatmap] geojson failed', e);
        document.getElementById('loading').innerHTML = '<span style="font-size:.8rem;color:var(--accent)">মানচিত্রের ফাইল লোড হয়নি।</span>';
    });
})();
</script>
</body>
</html>
