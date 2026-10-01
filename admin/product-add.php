<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/product-form.php';

$errors = [];
if (is_post()) {
    verify_csrf();
    [$errors, $id] = save_product_from_post(null, []);
    if (!$errors) {
        log_activity('product_create', input('name') . " (#$id)");
        flash('success', 'Product created.');
        redirect('admin/product-edit.php?id=' . $id);
    }
}

$defaults = ['status' => 'active', 'stock_qty' => 0, 'weight_lb' => '1.00'];

$pageTitle = 'Add product';
$activeNav = 'products';
$crumb = 'Products';
require __DIR__ . '/includes/header.php';
render_product_form($defaults, [], $errors, 'Create product');
require __DIR__ . '/includes/footer.php';
