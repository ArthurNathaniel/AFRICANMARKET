<?php
/**
 * Renders one product card. Expects $p from product_card_sql().
 */
function render_product_card(array $p): void
{
    $wished = in_array((int) $p['id'], wishlist_ids(), true);
    $onSale = $p['compare_price'] !== null && $p['compare_price'] > $p['price'];
    ?>
    <article class="p-card">
      <a class="img" href="<?= e(product_url($p)) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(image_url($p['image'])) ?>" alt="" loading="lazy"></a>
      <div class="tags">
        <?php if ($onSale): ?><span class="tag tag-sale">−<?= (int) round(100 - $p['price'] / $p['compare_price'] * 100) ?>%</span><?php endif; ?>
        <?php if ($p['stock_qty'] > 0 && $p['stock_qty'] <= low_stock()): ?><span class="tag tag-low">Only <?= (int) $p['stock_qty'] ?> left</span><?php endif; ?>
        <?php if ($p['stock_qty'] <= 0): ?><span class="tag">Sold out</span><?php endif; ?>
      </div>
      <form class="wish-form" method="post" action="<?= url('account/wishlist.php') ?>">
        <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
        <button class="wish-btn<?= $wished ? ' on' : '' ?>" type="submit" aria-label="<?= $wished ? 'Remove from' : 'Save to' ?> wishlist" aria-pressed="<?= $wished ? 'true' : 'false' ?>"><i class="bi bi-heart<?= $wished ? '-fill' : '' ?>"></i></button>
      </form>
      <div class="body">
        <?php if ($p['origin_country']): ?><span class="origin"><i class="bi bi-geo-alt"></i> <?= e($p['origin_country']) ?></span><?php endif; ?>
        <a class="name" href="<?= e(product_url($p)) ?>"><?= e($p['name']) ?></a>
        <?php if (!empty($p['rating'])): ?><div class="small"><?= stars_html((float) $p['rating'], (int) $p['review_count']) ?></div><?php endif; ?>
        <div class="price"><strong><?= money($p['price']) ?></strong><?php if ($onSale): ?><s><?= money($p['compare_price']) ?></s><?php endif; ?></div>
      </div>
    </article>
    <?php
}
