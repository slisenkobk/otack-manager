<?php
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= e(user_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Otack Manager</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
  :root {
    --paper:        #F5F0E6;
    --paper-2:      #ECE3D0;
    --ink:          #1A1612;
    --ink-2:        #2B221A;
    --ink-3:        #6E5F4D;
    --rule:         #D7C8AE;
    --accent:       #C75B30;
    --accent-soft:  rgba(199, 91, 48, 0.12);
    --shadow-pop:   0 24px 60px -22px rgba(26, 22, 18, 0.22), 0 2px 0 rgba(26, 22, 18, 0.05);
    --font-sans:    'Inter', system-ui, -apple-system, 'Helvetica Neue', sans-serif;
    --font-mono:    'JetBrains Mono', 'SFMono-Regular', ui-monospace, Menlo, monospace;
    --font-serif:   'Source Serif 4', 'Iowan Old Style', 'Apple Garamond', Georgia, serif;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }
  body {
    font-family: var(--font-sans);
    background: var(--paper);
    color: var(--ink);
    background-image:
      radial-gradient(circle at 1px 1px, rgba(26, 22, 18, 0.06) 1px, transparent 0);
    background-size: 22px 22px;
    background-position: 0 0;
    overflow: hidden;
  }
  .landing {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 32px;
  }
  .card {
    width: min(100%, 560px);
    background: var(--paper);
    border: 1px solid var(--rule);
    border-radius: 12px;
    padding: 56px 56px 44px;
    box-shadow: var(--shadow-pop);
    position: relative;
    overflow: hidden;
    animation: rise 0.6s cubic-bezier(0.2, 0.7, 0.3, 1) both;
  }
  .card::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, var(--accent-soft) 0%, transparent 55%);
    pointer-events: none;
  }
  .brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 36px;
    position: relative;
  }
  .brand__logo {
    width: 44px;
    height: 44px;
    background: var(--accent);
    border-radius: 9px;
    display: grid;
    place-items: center;
    color: #fff;
    font-weight: 800;
    font-family: var(--font-mono);
    font-size: 18px;
    letter-spacing: -0.04em;
  }
  .brand__name {
    font-family: var(--font-mono);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.18em;
    color: var(--ink-3);
    font-weight: 600;
  }
  .brand__name strong {
    color: var(--ink);
    font-weight: 700;
  }
  h1 {
    font-family: var(--font-serif);
    font-weight: 600;
    font-size: 44px;
    letter-spacing: -0.02em;
    line-height: 1.05;
    color: var(--ink);
    margin-bottom: 18px;
    position: relative;
  }
  h1 em {
    color: var(--accent);
    font-style: italic;
    font-weight: 600;
  }
  p {
    font-size: 16px;
    line-height: 1.6;
    color: var(--ink-2);
    margin-bottom: 28px;
    position: relative;
    max-width: 44ch;
  }
  .meta {
    display: flex;
    align-items: center;
    gap: 14px;
    padding-top: 20px;
    border-top: 1px solid var(--rule);
    font-family: var(--font-mono);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: var(--ink-3);
    font-weight: 600;
    position: relative;
  }
  .meta__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent);
    flex-shrink: 0;
  }
  .meta__sep {
    color: var(--rule);
  }
  .footer {
    position: fixed;
    bottom: 24px;
    left: 0;
    right: 0;
    text-align: center;
    font-family: var(--font-mono);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--ink-3);
    opacity: 0.6;
  }
  @keyframes rise {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  @media (max-width: 600px) {
    .card { padding: 40px 28px 32px; }
    h1 { font-size: 32px; }
  }
</style>
</head>
<body>
<main class="landing">
  <article class="card">
    <div class="brand">
      <span class="brand__logo">Ot</span>
      <span class="brand__name"><strong>Otack</strong> Manager</span>
    </div>
    <h1><?= t('landing.h1', ['app' => '<em>' . e(app_name()) . '</em>']) ?></h1>
    <p><?= e(t('landing.body_1')) ?></p>
    <p style="margin-bottom:0;"><?= e(t('landing.body_2')) ?></p>
    <div class="meta" style="margin-top:32px;">
      <span class="meta__dot"></span>
      <span><?= e(t('landing.tag_restricted')) ?></span>
      <span class="meta__sep">·</span>
      <span><?= e(t('landing.tag_invite')) ?></span>
    </div>
  </article>
</main>
<div class="footer">Otack Manager Ecosystem · By Goup Space</div>
</body>
</html>
