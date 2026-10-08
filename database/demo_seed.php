<?php
/**
 * Demo data so the store and admin panel have something realistic to show.
 * Called once by install.php when "Load demo data" is ticked.
 * Demo customers can sign in with password: Customer123
 */

function seed_demo_data(int $adminId): void
{
    mt_srand(2026);
    $pdo = db();
    $pdo->beginTransaction();

    // ---- Categories ----
    $tree = [
        ['Fashion & Textiles', ['Kente & Woven Cloth', 'Ankara Prints', 'Dashikis & Shirts']],
        ['Art & Home Decor', ['Wood Carvings', 'Paintings', 'Baskets & Weaving']],
        ['Beauty & Wellness', ['Shea Butter & Oils', 'African Black Soap']],
        ['Jewelry & Accessories', ['Beaded Jewelry', 'Leather Goods']],
        ['Food & Pantry', ['Spices & Seasonings', 'Coffee & Tea']],
        ['Music & Instruments', ['Drums & Percussion']],
    ];
    $cat = [];
    $sort = 0;
    foreach ($tree as [$parent, $children]) {
        q('INSERT INTO categories (name, slug, sort_order) VALUES (?, ?, ?)', [$parent, slugify($parent), ++$sort]);
        $pid = (int) $pdo->lastInsertId();
        $cat[$parent] = $pid;
        foreach ($children as $i => $child) {
            q('INSERT INTO categories (parent_id, name, slug, sort_order) VALUES (?, ?, ?, ?)', [$pid, $child, slugify($child), $i]);
            $cat[$child] = (int) $pdo->lastInsertId();
        }
    }

    // ---- Products: [name, category, price, compare, cost, weight, origin, stock, status, short] ----
    $products = [
        ['Handwoven Kente Stole – Asante Royal Pattern', 'Kente & Woven Cloth', 89.00, 110.00, 38.00, 0.6, 'Ghana', 34, 'active', 'Hand-loomed in Bonwire from cotton and rayon strips.'],
        ['Full Kente Cloth (12 strips) – Adwini Asa', 'Kente & Woven Cloth', 420.00, null, 210.00, 3.5, 'Ghana', 6, 'active', 'A ceremonial full cloth woven over several weeks.'],
        ['Kente Graduation Stole – Custom Text', 'Kente & Woven Cloth', 64.00, null, 24.00, 0.5, 'Ghana', 120, 'active', 'Personalised with your name, school and year.'],
        ['Kente Bow Tie & Pocket Square Set', 'Kente & Woven Cloth', 38.00, null, 14.00, 0.3, 'Ghana', 3, 'active', 'A sharp finishing touch for weddings and graduations.'],
        ['Ankara Wrap Midi Dress – Sunset Bloom', 'Ankara Prints', 78.00, 95.00, 30.00, 1.0, 'Nigeria', 22, 'active', 'Bold wax print cotton, tailored in Lagos.'],
        ['Men\'s Ankara Bomber Jacket', 'Ankara Prints', 115.00, null, 48.00, 1.8, 'Nigeria', 14, 'active', 'Fully lined, with ribbed cuffs and hem.'],
        ['Ankara Head Wrap – 6 Yard Gele', 'Ankara Prints', 29.00, null, 9.00, 0.4, 'Nigeria', 60, 'active', 'Enough fabric for a dramatic, sculpted gele.'],
        ['Classic Dashiki Shirt – Indigo', 'Dashikis & Shirts', 45.00, null, 16.00, 0.7, 'Nigeria', 40, 'active', 'Breathable cotton with hand-embroidered neckline.'],
        ['Agbada Three-Piece Set – Ivory & Gold', 'Dashikis & Shirts', 240.00, null, 105.00, 3.0, 'Nigeria', 5, 'draft', 'Flowing robe, tunic and trousers for special occasions.'],
        ['Maasai Beaded Collar Necklace', 'Beaded Jewelry', 58.00, null, 20.00, 0.4, 'Kenya', 25, 'active', 'Made by a women\'s beadwork cooperative in Narok.'],
        ['Beaded Leather Sandals', 'Beaded Jewelry', 49.00, 60.00, 18.00, 1.2, 'Kenya', 18, 'active', 'Hand-stitched leather with glass-bead straps.'],
        ['Maasai Shuka Blanket – Red Check', 'Kente & Woven Cloth', 36.00, null, 12.00, 1.5, 'Kenya', 45, 'active', 'The iconic checked cloth — a throw, picnic blanket or wrap.'],
        ['Stacking Bead Bracelets (Set of 5)', 'Beaded Jewelry', 24.00, null, 7.00, 0.2, 'Kenya', 90, 'active', 'Mix-and-match colours on stretch cord.'],
        ['Raw Unrefined Shea Butter – 16 oz', 'Shea Butter & Oils', 22.00, null, 7.50, 1.2, 'Ghana', 200, 'active', 'Grade A, hand-processed in Northern Ghana.'],
        ['Whipped Shea Body Butter – Lemongrass', 'Shea Butter & Oils', 18.00, null, 6.00, 0.6, 'Ghana', 140, 'active', 'Light, fluffy and fast-absorbing.'],
        ['Authentic African Black Soap – 1 lb', 'African Black Soap', 16.00, null, 5.00, 1.1, 'Ghana', 180, 'active', 'Made from plantain skin ash, cocoa pod and shea.'],
        ['Baobab Seed Oil – Cold Pressed 4 oz', 'Shea Butter & Oils', 26.00, null, 9.00, 0.4, 'Ghana', 0, 'active', 'Rich in vitamins A, D and E for skin and hair.'],
        ['Yirgacheffe Whole Bean Coffee – 1 lb', 'Coffee & Tea', 21.00, null, 9.50, 1.1, 'Ethiopia', 150, 'active', 'Floral, bright and tea-like. Roasted in small batches.'],
        ['Sidamo Natural Process – 12 oz', 'Coffee & Tea', 18.50, null, 8.00, 0.9, 'Ethiopia', 110, 'active', 'Blueberry and dark chocolate notes.'],
        ['Jebena Clay Coffee Pot', 'Coffee & Tea', 42.00, null, 15.00, 2.2, 'Ethiopia', 12, 'active', 'Traditional pot for an Ethiopian coffee ceremony.'],
        ['Berbere Spice Blend – 8 oz', 'Spices & Seasonings', 12.00, null, 4.00, 0.6, 'Ethiopia', 75, 'active', 'The heart of doro wat and misir wot.'],
        ['Jollof Rice Seasoning Kit', 'Spices & Seasonings', 14.00, null, 5.00, 0.8, 'Nigeria', 85, 'active', 'Everything you need for a party-size pot of jollof.'],
        ['Suya Pepper Spice – 6 oz', 'Spices & Seasonings', 9.50, null, 3.00, 0.5, 'Nigeria', 120, 'active', 'Smoky, nutty yaji spice for grilled meats.'],
        ['Moroccan Leather Weekender Bag', 'Leather Goods', 165.00, 199.00, 70.00, 3.2, 'Morocco', 9, 'active', 'Vegetable-tanned leather, hand-stitched in Marrakech.'],
        ['Babouche Leather Slippers', 'Leather Goods', 48.00, null, 17.00, 1.0, 'Morocco', 30, 'active', 'Soft pointed-toe slippers from the medina.'],
        ['Embossed Leather Pouf (unstuffed)', 'Wood Carvings', 95.00, null, 38.00, 2.5, 'Morocco', 11, 'active', 'Ships flat — stuff with spare linens or batting.'],
        ['Bolga Market Basket – Large', 'Baskets & Weaving', 54.00, null, 19.00, 1.6, 'Ghana', 28, 'active', 'Elephant-grass basket with a leather-wrapped handle.'],
        ['Woven Wall Plates (Set of 3)', 'Baskets & Weaving', 68.00, null, 24.00, 1.8, 'Ghana', 16, 'active', 'Instant colour for an empty wall.'],
        ['Bolga Fan – Leather Handle', 'Baskets & Weaving', 19.00, null, 6.00, 0.3, 'Ghana', 4, 'active', 'Handy on hot days, beautiful on a wall.'],
        ['Zulu Telephone-Wire Bowl', 'Baskets & Weaving', 74.00, null, 28.00, 0.9, 'South Africa', 13, 'active', 'Coiled from recycled telephone wire.'],
        ['Township Wire Art – Big Five', 'Wood Carvings', 88.00, null, 33.00, 1.4, 'South Africa', 7, 'active', 'Lion, leopard, elephant, rhino and buffalo in beads and wire.'],
        ['Ndebele Abstract Canvas Print', 'Paintings', 130.00, null, 52.00, 2.0, 'South Africa', 10, 'inactive', 'Geometric Ndebele-inspired print, ready to hang.'],
        ['Professional Djembe – 13" Head', 'Drums & Percussion', 285.00, null, 130.00, 14.0, 'Senegal', 4, 'active', 'Carved from solid lenke wood with a goat-skin head.'],
        ['Agaseke Peace Basket – Lidded', 'Baskets & Weaving', 46.00, null, 16.00, 0.5, 'Rwanda', 20, 'active', 'Tightly woven sisal with a pointed lid.'],
    ];
    $pr = [];
    foreach ($products as $i => [$name, $category, $price, $compare, $cost, $weight, $origin, $stock, $status, $short]) {
        $sku = 'JAM-' . str_pad((string) ($i + 1001), 5, '0', STR_PAD_LEFT);
        q('INSERT INTO products (category_id, name, slug, sku, short_description, description, price, compare_price, cost_price, stock_qty, weight_lb, origin_country, status, is_featured, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $cat[$category], $name, unique_slug('products', $name), $sku, $short,
            "$short\n\nEvery piece is made by hand in $origin, so colours and patterns vary slightly — no two are exactly alike.",
            $price, $compare, $cost, $stock, $weight, $origin, $status, $i % 6 === 0 ? 1 : 0,
            date('Y-m-d H:i:s', strtotime('-' . (180 - $i * 4) . ' days')),
        ]);
        if ($status === 'active') {
            $pr[] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'price' => $price, 'cost' => $cost, 'sku' => $sku, 'weight' => $weight];
        }
    }

    // ---- Customers (password: Customer123) ----
    $hash = password_hash('Customer123', PASSWORD_DEFAULT);
    $first = ['Aisha', 'Marcus', 'Jasmine', 'Darnell', 'Keisha', 'Andre', 'Imani', 'Tyrone', 'Nia', 'Jamal', 'Emily', 'Michael', 'Sarah', 'David', 'Maria', 'Carlos', 'Grace', 'Kofi', 'Chioma', 'Olivia', 'Ethan', 'Zara', 'Malik', 'Tiana', 'Brandon', 'Amara', 'Jordan', 'Naomi', 'Elijah', 'Yaa'];
    $last = ['Johnson', 'Williams', 'Brown', 'Jackson', 'Davis', 'Robinson', 'Harris', 'Thompson', 'Mensah', 'Okeke', 'Washington', 'Carter', 'Mitchell', 'Owusu', 'Adeyemi', 'Garcia', 'Lewis', 'Walker', 'Young', 'King'];
    $places = [['Atlanta', 'GA', '30303'], ['Houston', 'TX', '77002'], ['Brooklyn', 'NY', '11201'], ['Chicago', 'IL', '60601'], ['Washington', 'DC', '20001'],
               ['Charlotte', 'NC', '28202'], ['Los Angeles', 'CA', '90012'], ['Silver Spring', 'MD', '20910'], ['Newark', 'NJ', '07102'], ['Dallas', 'TX', '75201'],
               ['Philadelphia', 'PA', '19103'], ['Oakland', 'CA', '94612'], ['Columbus', 'OH', '43215'], ['Minneapolis', 'MN', '55401'], ['Seattle', 'WA', '98101'],
               ['Miami', 'FL', '33130'], ['Detroit', 'MI', '48226'], ['Honolulu', 'HI', '96813'], ['Boston', 'MA', '02108'], ['Denver', 'CO', '80202']];
    $streets = ['Peachtree St', 'Main St', 'Oak Ave', 'MLK Jr Blvd', 'Elm St', 'Park Ave', 'Maple Dr'];
    $customers = [];
    for ($i = 0; $i < 60; $i++) {
        $fn = $first[mt_rand(0, count($first) - 1)];
        $ln = $last[mt_rand(0, count($last) - 1)];
        $pl = $places[mt_rand(0, count($places) - 1)];
        $email = strtolower("$fn.$ln" . ($i + 1)) . '@example.com';
        $street = mt_rand(100, 9800) . ' ' . $streets[mt_rand(0, count($streets) - 1)];
        $phone = '(' . mt_rand(201, 989) . ') 555-' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        q('INSERT INTO customers (first_name, last_name, email, phone, password_hash, address_line1, city, state, zip, status, created_at, last_login_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
            $fn, $ln, $email, $phone, $hash, $street, $pl[0], $pl[1], $pl[2], $i === 7 ? 'suspended' : 'active',
            date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 170) . ' days')), date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 20) . ' days')),
        ]);
        $cid = (int) $pdo->lastInsertId();
        q('INSERT INTO customer_addresses (customer_id, label, full_name, line1, city, state, zip, phone, is_default) VALUES (?,?,?,?,?,?,?,?,1)',
          [$cid, 'Home', "$fn $ln", $street, $pl[0], $pl[1], $pl[2], $phone]);
        $customers[] = ['id' => $cid, 'name' => "$fn $ln", 'email' => $email, 'pl' => $pl, 'street' => $street, 'phone' => $phone];
    }

    // ---- Orders ----
    $seq = 100000;
    for ($n = 0; $n < 260; $n++) {
        // Skew towards recent days for an upward trend
        $daysAgo = (int) floor(150 * (1 - sqrt(mt_rand(0, 1000) / 1000)));
        $createdTs = strtotime("-$daysAgo days") - mt_rand(0, 86000);
        $created = date('Y-m-d H:i:s', $createdTs);
        $c = $customers[mt_rand(0, count($customers) - 1)];

        $lines = [];
        foreach ((array) array_rand($pr, mt_rand(1, 3)) as $pi) {
            $p = $pr[$pi];
            $qty = $p['price'] < 30 ? mt_rand(1, 3) : 1;
            $lines[] = [$p, $qty, round($p['price'] * $qty, 2)];
        }
        $subtotal = array_sum(array_column($lines, 2));
        $remote = in_array($c['pl'][1], ['HI', 'AK'], true);
        $method = mt_rand(1, 6) === 1 ? ['Priority (2–3 days)', $remote ? 24.99 : 12.99] : ['Standard', $subtotal >= 75 && !$remote ? 0 : ($remote ? 14.99 : 6.99)];
        $coupon = mt_rand(1, 9) === 1 ? 'AKWAABA10' : null;
        $discount = $coupon ? round($subtotal * 0.10, 2) : 0;
        $total = round($subtotal - $discount + $method[1], 2);

        if ($daysAgo > 10)     $status = mt_rand(1, 100) <= 93 ? 'delivered' : (mt_rand(0, 1) ? 'cancelled' : 'refunded');
        elseif ($daysAgo > 4)  $status = ['shipped', 'delivered', 'delivered', 'shipped'][mt_rand(0, 3)];
        elseif ($daysAgo > 1)  $status = ['processing', 'shipped', 'shipped'][mt_rand(0, 2)];
        else                   $status = ['pending', 'processing', 'processing'][mt_rand(0, 2)];
        $payment = match ($status) {
            'refunded'  => 'refunded',
            'cancelled' => mt_rand(0, 1) ? 'failed' : 'unpaid',
            'pending'   => 'unpaid',
            default     => 'paid',
        };
        $shipped = in_array($status, ['shipped', 'delivered'], true);
        $carrier = $shipped ? ['USPS', 'UPS', 'FedEx'][mt_rand(0, 2)] : null;
        $tracking = $shipped ? ($carrier === 'USPS' ? '9400' . mt_rand(100000000, 999999999) . mt_rand(100000000, 999999999) : ($carrier === 'UPS' ? '1Z' . strtoupper(bin2hex(random_bytes(8))) : (string) mt_rand(100000000000, 999999999999))) : null;

        q('INSERT INTO orders (order_number, customer_id, email, subtotal, discount_total, shipping_total, tax_total, grand_total, coupon_code, shipping_method, status, payment_status,
             ship_name, ship_line1, ship_city, ship_state, ship_zip, ship_phone, carrier, tracking_number, shipped_at, delivered_at, created_at)
           VALUES (?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            'JAM-' . (++$seq), $c['id'], $c['email'], $subtotal, $discount, $method[1], $total, $coupon, $method[0], $status, $payment,
            $c['name'], $c['street'], $c['pl'][0], $c['pl'][1], $c['pl'][2], $c['phone'], $carrier, $tracking,
            $shipped ? date('Y-m-d H:i:s', $createdTs + 86400) : null,
            $status === 'delivered' ? date('Y-m-d H:i:s', $createdTs + 86400 * mt_rand(3, 6)) : null,
            $created,
        ]);
        $oid = (int) $pdo->lastInsertId();
        foreach ($lines as [$p, $qty, $line]) {
            q('INSERT INTO order_items (order_id, product_id, product_name, sku, unit_price, unit_cost, quantity, line_total) VALUES (?,?,?,?,?,?,?,?)',
              [$oid, $p['id'], $p['name'], $p['sku'], $p['price'], $p['cost'], $qty, $line]);
        }
        if ($payment !== 'unpaid') {
            $ref = 'pi_' . bin2hex(random_bytes(10));
            $pstatus = ['paid' => 'completed', 'refunded' => 'refunded', 'failed' => 'failed'][$payment];
            q('INSERT INTO payments (order_id, gateway, transaction_ref, amount, status, created_at) VALUES (?,?,?,?,?,?)', [$oid, 'stripe', $ref, $total, $pstatus, $created]);
        }
    }

    // ---- Coupons ----
    $couponCount = (int) fetch_value("SELECT COUNT(*) FROM orders WHERE coupon_code = 'AKWAABA10' AND payment_status = 'paid'");
    q("INSERT INTO coupons (code, description, type, value, min_order, max_uses, used_count, starts_at, expires_at, is_active) VALUES
        ('AKWAABA10', 'Welcome – 10% off your first order', 'percent', 10, 0, NULL, ?, NULL, NULL, 1),
        ('SHEA15', '\$15 off beauty orders over \$75', 'fixed', 15, 75, 500, 41, NULL, ?, 1),
        ('JUNETEENTH25', 'Juneteenth celebration sale', 'percent', 25, 50, 1000, 312, ?, ?, 1),
        ('KARIBU20', 'Holiday preview – 20% off', 'percent', 20, 100, 300, 0, ?, ?, 1)", [
        $couponCount,
        date('Y-m-d 23:59:59', strtotime('+60 days')),
        date('Y-06-15 00:00:00'), date('Y-06-21 23:59:59'),
        date('Y-m-d 00:00:00', strtotime('+30 days')), date('Y-m-d 23:59:59', strtotime('+75 days')),
    ]);

    // ---- Reviews ----
    $titles = [5 => ['Absolutely stunning', 'Worth every penny', 'Beautiful craftsmanship', 'Exceeded expectations'],
               4 => ['Lovely quality', 'Great gift', 'Very happy'], 3 => ['Nice but smaller than expected', 'Okay'],
               2 => ['Arrived damaged'], 1 => ['Not as pictured']];
    $bodies = [5 => 'You can tell this was made by hand with real care. Arrived quickly and beautifully packed.',
               4 => 'Really nice piece and great quality. Colours were slightly different from the photo but I love it.',
               3 => 'Good quality overall, though it was a little smaller than I expected.',
               2 => 'The box was crushed in transit. Support was helpful and sent a replacement.',
               1 => 'The colours were very different from the photos.'];
    for ($i = 0; $i < 70; $i++) {
        $r = [5, 5, 5, 5, 4, 4, 4, 3, 2, 1][mt_rand(0, 9)];
        $p = $pr[mt_rand(0, count($pr) - 1)];
        $c = $customers[mt_rand(0, count($customers) - 1)];
        $status = $i < 9 ? 'pending' : ($r <= 1 && mt_rand(0, 1) ? 'rejected' : 'approved');
        $reply = $status === 'approved' && $r <= 3 ? 'Thank you for the honest feedback — we\'ve shared it with our makers. Please email us if there\'s anything we can do.' : null;
        q('INSERT INTO reviews (product_id, customer_id, rating, title, body, status, reply, created_at) VALUES (?,?,?,?,?,?,?,?)',
          [$p['id'], $c['id'], $r, $titles[$r][mt_rand(0, count($titles[$r]) - 1)], $bodies[$r], $status, $reply,
           date('Y-m-d H:i:s', strtotime('-' . ($i < 9 ? mt_rand(0, 3) : mt_rand(4, 120)) . ' days'))]);
    }

    // ---- Wishlists ----
    foreach (array_slice($customers, 0, 15) as $c) {
        foreach ((array) array_rand($pr, 3) as $pi) {
            q('INSERT IGNORE INTO wishlists (customer_id, product_id) VALUES (?, ?)', [$c['id'], $pr[$pi]['id']]);
        }
    }

    // ---- Shipping (domestic, from a US location) ----
    $states = array_keys(us_states());
    $contiguous = array_values(array_diff($states, ['AK', 'HI', 'PR', 'GU', 'VI']));
    $zones = [
        ['Contiguous United States', $contiguous, [
            ['Standard', 'flat', 6.99, 0, 75, 3, 6],
            ['Priority (2–3 days)', 'flat', 12.99, 0, null, 2, 3],
            ['Express (1–2 days)', 'per_lb', 24.99, 1.50, null, 1, 2],
        ]],
        ['Alaska & Hawaii', ['AK', 'HI'], [
            ['Standard', 'flat', 14.99, 0, 150, 5, 9],
            ['Priority (2–3 days)', 'flat', 24.99, 0, null, 2, 4],
        ]],
        ['US Territories', ['PR', 'GU', 'VI'], [
            ['Standard', 'per_lb', 19.99, 2.00, null, 6, 12],
        ]],
    ];
    foreach ($zones as $zi => [$zname, $regions, $methods]) {
        q('INSERT INTO shipping_zones (name, regions, sort_order) VALUES (?, ?, ?)', [$zname, implode(',', $regions), $zi + 1]);
        $zid = (int) $pdo->lastInsertId();
        foreach ($methods as [$mname, $type, $base, $perLb, $freeOver, $min, $max]) {
            q('INSERT INTO shipping_methods (zone_id, name, rate_type, base_cost, per_lb_cost, free_over, min_days, max_days) VALUES (?,?,?,?,?,?,?,?)',
              [$zid, $mname, $type, $base, $perLb, $freeOver, $min, $max]);
        }
    }

    q("INSERT INTO activity_log (admin_id, action, details) VALUES (?, 'install', 'Store installed with demo data')", [$adminId]);
    $pdo->commit();
}
