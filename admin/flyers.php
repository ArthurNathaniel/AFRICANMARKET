<?php
/**
 * Flyer maker: builds shareable promo images (Happy New Month, Deal of the Week,
 * New Arrivals, Holiday) from live products. Drawn in the browser on a <canvas>
 * by assets/js/flyers.js and downloaded as PNG; nothing is stored on the server.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/shop.php';

$products = array_map(fn($p) => [
    'id'      => (int) $p['id'],
    'name'    => $p['name'],
    'price'   => (float) $p['price'],
    'compare' => $p['compare_price'] !== null ? (float) $p['compare_price'] : null,
    'image'   => image_url($p['image']),
    'created' => $p['created_at'],
    'featured'=> (bool) $p['is_featured'],
    'stock'   => (int) $p['stock_qty'],
    'sold'    => (int) $p['sold'],
], fetch_all("SELECT p.id, p.name, p.price, p.compare_price, p.image, p.created_at, p.is_featured, p.stock_qty,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.product_id = p.id AND o.payment_status = 'paid') AS sold
              FROM products p WHERE p.status = 'active' ORDER BY p.created_at DESC"));

$host = parse_url(absolute_url('/'), PHP_URL_HOST) ?: '';
$data = [
    'products' => $products,
    'logo'     => asset('img/logo-badge.png'),
    'store'    => setting('store_name', APP_NAME),
    'website'  => preg_replace('/^www\./', '', $host) === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) ? 'jenmondafricanmarket.com' : $host,
    'phones'   => implode('  •  ', support_phones()),
    'whatsapp' => (string) setting('whatsapp_number', ''),
    'freeShip' => !fetch_value("SELECT COUNT(*) FROM shipping_methods WHERE is_active = 1 AND rate_type <> 'free'"),
];

$pageTitle = 'Flyers';
$activeNav = 'flyers';
$crumb = 'Marketing';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Make a ready-to-post flyer with your logo, products and contacts. Download it, then share on WhatsApp Status, Instagram or Facebook.</p></div>
</div>

<div class="flyer-layout">
  <form class="card" id="flyerForm" onsubmit="return false">
    <div class="card-head"><h2>Flyer details</h2></div>
    <div class="card-body">
      <div class="field"><span class="label">Type</span>
        <div class="flyer-types">
          <label><input type="radio" name="type" value="newmonth" checked><span><i class="bi bi-calendar-heart"></i>Happy New Month</span></label>
          <label><input type="radio" name="type" value="deal"><span><i class="bi bi-lightning-charge"></i>Deal of the Week</span></label>
          <label><input type="radio" name="type" value="arrival"><span><i class="bi bi-stars"></i>New Arrivals</span></label>
          <label><input type="radio" name="type" value="holiday"><span><i class="bi bi-gift"></i>Holiday</span></label>
          <label><input type="radio" name="type" value="flash"><span><i class="bi bi-lightning"></i>Flash Sale</span></label>
          <label><input type="radio" name="type" value="weekend"><span><i class="bi bi-sun"></i>Weekend Special</span></label>
          <label><input type="radio" name="type" value="bestseller"><span><i class="bi bi-trophy"></i>Best Sellers</span></label>
          <label><input type="radio" name="type" value="restock"><span><i class="bi bi-arrow-repeat"></i>Back in Stock</span></label>
          <label><input type="radio" name="type" value="combo"><span><i class="bi bi-basket"></i>Combo Deal</span></label>
          <label><input type="radio" name="type" value="freeship"><span><i class="bi bi-truck"></i>Free Delivery</span></label>
          <label><input type="radio" name="type" value="marketday"><span><i class="bi bi-shop"></i>Market Day</span></label>
          <label><input type="radio" name="type" value="thanks"><span><i class="bi bi-heart"></i>Thank You</span></label>
        </div>
      </div>

      <div class="form-row">
        <div class="field" data-for="newmonth"><label for="fMonth">Month</label><select id="fMonth"></select></div>
        <div class="field" data-for="holiday" hidden><label for="fHoliday">Holiday</label><select id="fHoliday"></select></div>
        <div class="field" data-for="deal" hidden><label for="fDates">Valid dates</label><input class="input" id="fDates"></div>
        <div class="field" data-for="marketday" hidden><label for="fWhen">Day &amp; time</label><input class="input" id="fWhen" maxlength="40" placeholder="e.g. SAT OCT 18 · 10AM – 4PM"></div>
        <div class="field"><label for="fSize">Size</label>
          <select id="fSize">
            <option value="1080x1080">Square · Instagram / Facebook post</option>
            <option value="1080x1350">Portrait · Instagram feed</option>
            <option value="1080x1920">Story · WhatsApp Status / Reels</option>
          </select></div>
      </div>

      <div class="form-row">
        <div class="field"><label for="fBg">Background</label>
          <select id="fBg">
            <option value="auto">Match flyer type</option>
            <optgroup label="Colours">
              <option value="forest">Forest green</option>
              <option value="sunset">Sunset orange</option>
              <option value="ruby">Ruby red</option>
              <option value="royal">Royal purple</option>
              <option value="midnight">Midnight blue</option>
              <option value="teal">Teal</option>
              <option value="gold">Gold</option>
              <option value="cream">Cream</option>
              <option value="blackgold">Black &amp; gold</option>
              <option value="ghana">Ghana flag</option>
              <option value="naija">Naija green &amp; white</option>
            </optgroup>
            <optgroup label="Special">
              <option value="photo">Blurred product photo</option>
              <option value="custom">Custom colour…</option>
            </optgroup>
          </select>
          <input type="color" id="fColor" value="#0d4a32" hidden style="margin-top:8px;width:100%;height:40px;border:1px solid var(--border-strong);border-radius:8px;padding:2px;background:#fff" aria-label="Custom background colour"></div>
        <div class="field"><label for="fPattern">Pattern</label>
          <select id="fPattern">
            <option value="auto">Match flyer type</option>
            <option value="none">None (plain)</option>
            <option value="confetti">Confetti</option>
            <option value="sparkle">Sparkles</option>
            <option value="star">Stars</option>
            <option value="burst">Sun rays</option>
            <option value="dots">Bubbles</option>
            <option value="kente">Kente stripes</option>
            <option value="ankara">Ankara diamonds</option>
            <option value="waves">Waves</option>
          </select></div>
      </div>

      <div class="field"><label for="fEyebrow">Top label</label><input class="input" id="fEyebrow" maxlength="40"></div>
      <div class="field"><label for="fHeadline">Headline</label><input class="input" id="fHeadline" maxlength="60"></div>
      <div class="field"><label for="fSub">Message</label><textarea class="input" id="fSub" rows="2" maxlength="140"></textarea></div>
      <div class="field"><label for="fOffer">Sticker <span class="muted">(optional)</span></label><input class="input" id="fOffer" maxlength="24" placeholder="e.g. UP TO 30% OFF">
        <div class="hint">Short text for the round badge. Leave blank to hide it.</div></div>

      <div class="field">
        <div style="display:flex;justify-content:space-between;align-items:baseline"><span class="label">Products <span class="muted" id="pickCount"></span></span>
          <button class="btn btn-ghost btn-sm" type="button" id="autoPick"><i class="bi bi-magic"></i>Auto-pick</button></div>
        <input class="input" id="pickSearch" placeholder="Search products…" style="margin-bottom:8px">
        <div class="flyer-picks" id="picks"></div>
        <div class="hint">Choose up to 4. Their photos and prices go on the flyer.</div>
      </div>

      <div class="form-row">
        <div class="field"><label for="fWebsite">Website</label><input class="input" id="fWebsite"></div>
        <div class="field"><label for="fWhatsapp">WhatsApp</label><input class="input" id="fWhatsapp"></div>
      </div>
      <div class="field"><label for="fPhones">Phone line</label><input class="input" id="fPhones"></div>
      <label class="check"><input type="checkbox" id="fPrices" checked> Show prices</label>
    </div>
  </form>

  <div class="card flyer-preview">
    <div class="card-head"><h2>Preview</h2><span class="small muted" id="sizeLabel"></span></div>
    <div class="card-body">
      <canvas id="flyerCanvas" width="1080" height="1080" aria-label="Flyer preview"></canvas>
      <div class="form-actions" style="justify-content:stretch;margin-top:14px">
        <button class="btn btn-primary" type="button" id="downloadBtn" style="flex:1"><i class="bi bi-download"></i>Download PNG</button>
        <button class="btn" type="button" id="shareBtn" hidden style="flex:1"><i class="bi bi-share"></i>Share</button>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="flyerData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<?php
$pageScripts = '<script src="' . asset('js/flyers.js') . '"></script>';
require __DIR__ . '/includes/footer.php';
