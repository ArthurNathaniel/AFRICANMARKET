<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $action = input('action');
    if ($action === 'reply') {
        $reply = mb_substr(input('reply'), 0, 2000);
        q('UPDATE reviews SET reply = ? WHERE id = ?', [$reply !== '' ? $reply : null, input_int('id')]);
        log_activity('review_reply', 'Review #' . input_int('id'));
        flash('success', $reply !== '' ? 'Reply saved — it shows under the review on the product page.' : 'Reply removed.');
        redirect('admin/reviews.php' . query_with([]));
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [input_int('id')]))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'approve' || $action === 'reject') {
            q("UPDATE reviews SET status = ? WHERE id IN ($in)", array_merge([$action === 'approve' ? 'approved' : 'rejected'], $ids));
            flash('success', count($ids) . ' review(s) ' . ($action === 'approve' ? 'published.' : 'rejected.'));
        } elseif ($action === 'delete') {
            q("DELETE FROM reviews WHERE id IN ($in)", $ids);
            flash('success', count($ids) . ' review(s) deleted.');
        }
        log_activity('review_' . $action, 'Review IDs: ' . implode(', ', $ids));
    }
    redirect('admin/reviews.php' . query_with([]));
}

$status = input('status', 'pending');
$rating = input_int('rating');
$search = input('q');
$where = ['1=1']; $params = [];
if ($status !== 'all') { $where[] = 'r.status = ?'; $params[] = $status; }
if ($rating) { $where[] = 'r.rating = ?'; $params[] = $rating; }
if ($search !== '') { $where[] = '(p.name LIKE ? OR r.title LIKE ? OR r.body LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$whereSql = implode(' AND ', $where);

$pg = paginate((int) fetch_value("SELECT COUNT(*) FROM reviews r JOIN products p ON p.id = r.product_id WHERE $whereSql", $params), 20);
$reviews = fetch_all(
    "SELECT r.*, p.name product, p.image, CONCAT(c.first_name, ' ', LEFT(c.last_name, 1), '.') customer
     FROM reviews r JOIN products p ON p.id = r.product_id
     LEFT JOIN customers c ON c.id = r.customer_id
     WHERE $whereSql ORDER BY r.created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", $params);
$counts = array_column(fetch_all('SELECT status, COUNT(*) n FROM reviews GROUP BY status'), 'n', 'status');
$avg = (float) fetch_value("SELECT AVG(rating) FROM reviews WHERE status = 'approved'");

$pageTitle = 'Reviews';
$activeNav = 'reviews';
$crumb = 'Catalog';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><div><p class="mt-0">Moderate customer reviews before they appear on product pages. Average published rating: <strong><?= number_format($avg, 2) ?> ★</strong></p></div></div>
<div class="card">
  <div class="tabs">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Published', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $l): ?>
      <a href="<?= e(query_with(['status' => $k, 'page' => null])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $l ?><span class="count"><?= $k === 'all' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="search"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($search) ?>" placeholder="Search product or review text…"></div>
    <select name="rating" onchange="this.form.submit()"><option value="">Any rating</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $rating === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?></select>
    <button class="btn" type="submit">Filter</button>
  </form>
  <form method="post">
    <?= csrf_field() ?>
    <div class="filters" style="padding:10px 16px">
      <label class="check small"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"> Select all</label>
      <button class="btn btn-sm btn-primary" name="action" value="approve"><i class="bi bi-check2"></i>Publish selected</button>
      <button class="btn btn-sm" name="action" value="reject">Reject selected</button>
      <button class="btn btn-sm btn-danger" name="action" value="delete" onclick="return confirm('Delete the selected reviews permanently?')">Delete</button>
    </div>
    <ul class="list">
      <?php foreach ($reviews as $r): ?>
        <li style="align-items:flex-start">
          <input type="checkbox" class="row-check" name="ids[]" value="<?= $r['id'] ?>" style="margin-top:14px" aria-label="Select review">
          <img class="thumb" src="<?= e(image_url($r['image'])) ?>" alt="">
          <div class="grow">
            <div class="title"><a href="<?= url('admin/product-edit.php?id=' . $r['product_id']) ?>"><?= e($r['product']) ?></a></div>
            <div><span class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= str_repeat('★', (int) $r['rating']) ?><span class="off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span> <strong><?= e($r['title']) ?></strong></div>
            <div class="text-2" style="margin-top:4px"><?= nl2br(e($r['body'])) ?></div>
            <div class="meta" style="margin-top:6px"><?= e($r['customer'] ?? 'Deleted customer') ?> · <?= time_ago($r['created_at']) ?> · <?= status_badge($r['status']) ?></div>
            <?php if ($r['reply']): ?><div class="small" style="margin-top:8px;padding:8px 12px;background:var(--surface-2);border-left:3px solid var(--gold-500);border-radius:6px"><strong>Your reply:</strong> <?= nl2br(e($r['reply'])) ?></div><?php endif; ?>
          </div>
          <div class="nowrap">
            <?php if ($r['status'] !== 'approved'): ?><button class="btn btn-sm btn-primary" form="reviewApprove" name="id" value="<?= $r['id'] ?>">Publish</button><?php endif; ?>
            <?php if ($r['status'] !== 'rejected'): ?><button class="btn btn-sm" form="reviewReject" name="id" value="<?= $r['id'] ?>">Reject</button><?php endif; ?>
            <button type="button" class="btn btn-sm" data-modal="replyModal" data-fill-id="<?= $r['id'] ?>" data-fill-reply="<?= e($r['reply']) ?>"><i class="bi bi-reply"></i><?= $r['reply'] ? 'Edit reply' : 'Reply' ?></button>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$reviews): ?><li class="empty" style="display:block"><i class="bi bi-star"></i>No reviews here.</li><?php endif; ?>
    </ul>
  </form>
  <?= pagination_links($pg) ?>
</div>
<form method="post" id="reviewApprove"><?= csrf_field() ?><input type="hidden" name="action" value="approve"></form>
<form method="post" id="reviewReject"><?= csrf_field() ?><input type="hidden" name="action" value="reject"></form>
<div class="modal-backdrop" id="replyModal" role="dialog" aria-modal="true" aria-labelledby="replyTitle">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id">
    <div class="card-head"><h3 id="replyTitle">Public reply</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="card-body"><div class="field"><label for="reply">Shown under the review as “<?= e(setting('store_name', APP_NAME)) ?> replied”. Leave empty to remove.</label><textarea class="input" id="reply" name="reply" rows="4" maxlength="2000"></textarea></div></div>
    <div class="card-foot form-actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save reply</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
