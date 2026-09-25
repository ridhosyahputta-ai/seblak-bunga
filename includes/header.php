<?php

if (!isset($page_title))  { $page_title = ''; }
if (!isset($active_page)) { $active_page = ''; }

function navClass($page, $active_page) {
    return $page === $active_page ? ' class="active"' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ? htmlspecialchars($page_title) . ' — ' : '' ?>Seblak Bunga</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Poppins:wght@400;500;600&family=Caveat:wght@600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #F5EDE0;
    --bg-card: #FFFCF6;
    --text: #2B2420;
    --text-secondary: #6B6459;
    --accent: #C1372A;
    --accent-dark: #8F2A20;
    --accent-2: #E8A33D;
    --accent-3: #4A7A3C;
    --border: rgba(43,36,32,0.12);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    line-height: 1.6;
    overflow-x: hidden;
  }
  .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }

  header {
    position: sticky; top: 0; z-index: 10;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
  }
  .navbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; max-width: 1080px; margin: 0 auto;
  }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand span { font-family: 'Anton', sans-serif; font-size: 20px; letter-spacing: 0.3px; }
  nav.links { display: none; gap: 24px; font-size: 14px; font-weight: 500; }
  nav.links a { color: var(--text); text-decoration: none; }
  nav.links a.active { color: var(--accent); }
  .btn-order-small {
    background: var(--accent); color: #FFF8EF; border: none; border-radius: 100px;
    padding: 9px 18px; font-family: 'Poppins'; font-weight: 500; font-size: 13px;
    text-decoration: none; white-space: nowrap;
  }
  @media (min-width: 720px) { nav.links { display: flex; } }

  .hero {
    position: relative; padding: 48px 0 60px;
    background-image: radial-gradient(var(--text) 0.8px, transparent 0.8px);
    background-size: 18px 18px; background-position: -9px -9px; background-color: var(--bg);
  }
  .hero-inner { background: var(--bg); max-width: 640px; }
  .eyebrow { font-family: 'Caveat', cursive; font-size: 22px; color: var(--accent-3); display: inline-block; margin-bottom: 4px; }
  h1.headline { font-family: 'Anton', sans-serif; font-size: clamp(34px, 8vw, 56px); line-height: 1.05; margin: 0 0 14px; text-transform: lowercase; }
  .headline .hl { color: var(--accent); }
  p.sub { font-size: 15px; color: var(--text-secondary); max-width: 460px; margin: 0 0 26px; }
  .cta-row { display: flex; gap: 12px; flex-wrap: wrap; }
  .btn-primary, .btn-secondary {
    font-family: 'Poppins'; font-weight: 500; font-size: 14px;
    padding: 13px 22px; border-radius: 100px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
  }
  .btn-primary { background: var(--accent); color: #FFF8EF; border: none; }
  .btn-secondary { background: transparent; color: var(--text); border: 1.5px solid var(--text); }

  .wave { display: block; width: 100%; height: 40px; }

  section { padding: 50px 0; }
  section.page-header { padding-bottom: 10px; }
  .section-label { font-family: 'Caveat', cursive; font-size: 20px; color: var(--accent-3); margin: 0 0 2px; }
  h2.section-title { font-family: 'Anton', sans-serif; font-size: 28px; margin: 0 0 30px; text-transform: lowercase; }
  h1.page-title { font-family: 'Anton', sans-serif; font-size: clamp(28px, 6vw, 40px); margin: 0 0 30px; text-transform: lowercase; }

  .about-block { display: flex; gap: 28px; flex-wrap: wrap; align-items: center; }
  .about-text { flex: 1; min-width: 240px; }
  .about-text p { color: var(--text-secondary); font-size: 14.5px; }
  .about-badge {
    background: var(--accent-2); color: var(--text); font-family: 'Anton';
    font-size: 15px; padding: 16px; border-radius: 14px; width: 130px; text-align: center;
    transform: rotate(-3deg); flex-shrink: 0;
  }

  .link-more {
    color: var(--accent); font-weight: 500; font-size: 13px; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px; margin-top: 6px;
  }

  .base-price-card {
    background: var(--accent); color: #FFF8EF; border-radius: 16px; padding: 20px 22px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    margin-bottom: 22px;
  }
  .base-price-card .bp-label { font-size: 13px; opacity: 0.85; }
  .base-price-card .bp-value { font-family: 'Anton'; font-size: 26px; }
  .pedas-note {
    display: flex; align-items: center; gap: 12px; background: var(--bg);
    border: 1.5px dashed var(--text); border-radius: 14px; padding: 14px 16px; margin-bottom: 26px;
  }
  .pedas-note .pn-tag { font-family: 'Caveat'; font-size: 22px; color: var(--accent); flex-shrink: 0; }
  .pedas-note p { margin: 0; font-size: 13px; color: var(--text-secondary); }

  .topping-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
  .topping-chip {
    background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px;
    padding: 12px 14px; display: flex; flex-direction: column; gap: 4px;
  }
  .topping-chip .tp-name { font-size: 13.5px; font-weight: 500; }
  .topping-chip .tp-price { font-family: 'Caveat'; font-size: 18px; color: var(--accent-3); }
  .topping-chip .tp-price.confirmed { color: var(--accent); }
  .topping-note { font-size: 11.5px; color: var(--text-secondary); margin-top: 16px; opacity: 0.8; }

  .info-list { display: flex; flex-direction: column; gap: 16px; margin-top: 26px; }
  .info-row { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
  .info-row .info-label { font-weight: 600; font-size: 13.5px; min-width: 140px; flex-shrink: 0; }
  .info-row .info-value { font-size: 13.5px; color: var(--text-secondary); }

  .order-section { background: var(--text); color: var(--bg); border-radius: 22px; padding: 36px 26px; text-align: center; }
  .order-section h2, .order-section h1 { color: var(--bg); }
  .order-section p { color: var(--bg); opacity: 0.75; font-size: 14px; max-width: 420px; margin: 0 auto 22px; }
  .btn-whatsapp {
    background: var(--accent-3); color: #FFF8EF; font-weight: 500; font-size: 14px;
    padding: 14px 26px; border-radius: 100px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
  }

  footer { padding: 26px 0 40px; text-align: center; }
  footer p { font-size: 12.5px; color: var(--text-secondary); margin: 0; }
</style>
</head>
<body>

<header>
  <div class="navbar">
    <div class="brand">
      <svg width="30" height="30" viewBox="0 0 100 100" aria-hidden="true">
        <g transform="rotate(-20 50 50)">
          <rect x="45" y="54" width="10" height="38" rx="5" fill="var(--accent-dark)"/>
          <path d="M26 10 Q50 -2 74 10 Q82 32 74 50 Q50 62 26 50 Q18 32 26 10 Z" fill="var(--accent)" stroke="var(--accent-dark)" stroke-width="2.5"/>
          <circle cx="40" cy="26" r="7" fill="var(--bg)"/>
          <circle cx="60" cy="26" r="7" fill="var(--bg)"/>
          <circle cx="50" cy="42" r="7" fill="var(--bg)"/>
        </g>
      </svg>
      <span>seblak bunga</span>
    </div>
    <nav class="links">
      <a href="index.php"<?= navClass('beranda', $active_page) ?>>Beranda</a>
      <a href="menu.php"<?= navClass('menu', $active_page) ?>>Menu</a>
      <a href="tentang.php"<?= navClass('tentang', $active_page) ?>>Tentang</a>
      <a href="kontak.php"<?= navClass('kontak', $active_page) ?>>Kontak</a>
    </nav>
    <a href="kontak.php" class="btn-order-small">Pesan</a>
  </div>
</header>