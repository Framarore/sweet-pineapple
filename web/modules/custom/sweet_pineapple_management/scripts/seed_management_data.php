<?php

declare(strict_types=1);

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\Store;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$entity_type_manager = \Drupal::entityTypeManager();
$store_storage = $entity_type_manager->getStorage('commerce_store');
$user_storage = $entity_type_manager->getStorage('user');
$variation_storage = $entity_type_manager->getStorage('commerce_product_variation');

$stores = [
  [
    'name' => 'Sweet Pineapple Centro',
    'mail' => 'centro@sweetpineapple.local',
    'address' => [
      'country_code' => 'ES',
      'administrative_area' => 'Madrid',
      'locality' => 'Madrid',
      'address_line1' => 'Calle Mayor 12',
      'postal_code' => '28013',
    ],
  ],
  [
    'name' => 'Sweet Pineapple Norte',
    'mail' => 'norte@sweetpineapple.local',
    'address' => [
      'country_code' => 'ES',
      'administrative_area' => 'Madrid',
      'locality' => 'Alcobendas',
      'address_line1' => 'Avenida Europa 8',
      'postal_code' => '28108',
    ],
  ],
  [
    'name' => 'Sweet Pineapple Sur',
    'mail' => 'sur@sweetpineapple.local',
    'address' => [
      'country_code' => 'ES',
      'administrative_area' => 'Madrid',
      'locality' => 'Getafe',
      'address_line1' => 'Calle Madrid 44',
      'postal_code' => '28901',
    ],
  ],
];

$created_stores = [];
foreach ($stores as $definition) {
  $existing = $store_storage->loadByProperties(['name' => $definition['name']]);
  $store = $existing ? reset($existing) : NULL;

  if (!$store) {
    $store = Store::create([
      'type' => 'online',
      'name' => $definition['name'],
      'mail' => $definition['mail'],
      'default_currency' => 'EUR',
      'timezone' => 'Europe/Madrid',
      'billing_countries' => ['ES'],
      'address' => $definition['address'],
    ]);
    $store->save();
  }

  $created_stores[] = $store;
}

$employees = [
  ['name' => 'ana.centro', 'mail' => 'ana.centro@sweetpineapple.local', 'store' => 0, 'position' => 'manager', 'phone' => '600100001'],
  ['name' => 'luis.centro', 'mail' => 'luis.centro@sweetpineapple.local', 'store' => 0, 'position' => 'pastry_chef', 'phone' => '600100002'],
  ['name' => 'marta.centro', 'mail' => 'marta.centro@sweetpineapple.local', 'store' => 0, 'position' => 'sales', 'phone' => '600100003'],
  ['name' => 'sara.norte', 'mail' => 'sara.norte@sweetpineapple.local', 'store' => 1, 'position' => 'manager', 'phone' => '600200001'],
  ['name' => 'pablo.norte', 'mail' => 'pablo.norte@sweetpineapple.local', 'store' => 1, 'position' => 'pastry_chef', 'phone' => '600200002'],
  ['name' => 'ines.norte', 'mail' => 'ines.norte@sweetpineapple.local', 'store' => 1, 'position' => 'sales', 'phone' => '600200003'],
  ['name' => 'elena.sur', 'mail' => 'elena.sur@sweetpineapple.local', 'store' => 2, 'position' => 'manager', 'phone' => '600300001'],
  ['name' => 'diego.sur', 'mail' => 'diego.sur@sweetpineapple.local', 'store' => 2, 'position' => 'pastry_chef', 'phone' => '600300002'],
  ['name' => 'clara.sur', 'mail' => 'clara.sur@sweetpineapple.local', 'store' => 2, 'position' => 'sales', 'phone' => '600300003'],
];

$employee_users = [];
foreach ($employees as $definition) {
  $existing = $user_storage->loadByProperties(['name' => $definition['name']]);
  $account = $existing ? reset($existing) : NULL;

  if (!$account) {
    $account = User::create([
      'name' => $definition['name'],
      'mail' => $definition['mail'],
      'pass' => bin2hex(random_bytes(16)),
      'status' => 1,
      'roles' => ['sweet_pineapple_employee'],
      'field_sp_store' => $created_stores[$definition['store']]->id(),
      'field_sp_position' => $definition['position'],
      'field_sp_phone' => $definition['phone'],
      'field_sp_hire_date' => date('Y-m-d', strtotime('-' . (60 + ($definition['store'] * 15)) . ' days')),
    ]);
    $account->save();
  }

  $employee_users[] = $account;
}

$created_shifts = 0;
foreach ($employee_users as $index => $account) {
  $store_id = (int) $account->get('field_sp_store')->target_id;
  for ($day = 0; $day < 28; $day++) {
    if (($day + $index) % 4 === 0) {
      continue;
    }

    $date = strtotime('-' . $day . ' days 09:00:00');
    $title = 'Turno ' . $account->getAccountName() . ' ' . date('Y-m-d', $date);
    $existing = $entity_type_manager->getStorage('node')->loadByProperties([
      'type' => 'sp_shift',
      'title' => $title,
    ]);
    if ($existing) {
      continue;
    }

    Node::create([
      'type' => 'sp_shift',
      'title' => $title,
      'uid' => 1,
      'status' => 1,
      'field_sp_store' => $store_id,
      'field_sp_employee' => $account->id(),
      'field_sp_shift_start' => date('Y-m-d\TH:i:s', $date),
      'field_sp_shift_end' => date('Y-m-d\TH:i:s', strtotime('+8 hours', $date)),
      'field_sp_shift_status' => ($day + $index) % 19 === 0 ? 'absence' : 'completed',
    ])->save();
    $created_shifts++;
  }
}

$variation_ids = $variation_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 1)
  ->range(0, 80)
  ->execute();
$variations = array_values($variation_storage->loadMultiple($variation_ids));

if ($variations === []) {
  throw new RuntimeException('No product variations were found. Seed the product catalog first.');
}

$created_orders = 0;
$existing_demo_order_ids = $entity_type_manager->getStorage('commerce_order')->getQuery()
  ->accessCheck(FALSE)
  ->condition('order_number', 'SP-DEMO-', 'STARTS_WITH')
  ->execute();

if (count($existing_demo_order_ids) < 160) {
  for ($i = count($existing_demo_order_ids); $i < 160; $i++) {
  $placed = strtotime('-' . random_int(0, 89) . ' days ' . random_int(10, 20) . ':' . random_int(0, 59) . ':00');
  $store = $created_stores[$i % count($created_stores)];
  $order_number = 'SP-DEMO-' . date('Ymd', $placed) . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);

  if ($entity_type_manager->getStorage('commerce_order')->loadByProperties(['order_number' => $order_number])) {
    continue;
  }

  $order_items = [];
  $line_count = random_int(1, 3);
  $order_total = 0.0;

  for ($line = 0; $line < $line_count; $line++) {
    $variation = $variations[array_rand($variations)];
    $quantity = random_int(1, 4);
    $price = $variation->getPrice() ?: new Price('10.00', 'EUR');
    $line_total = (float) $price->getNumber() * $quantity;
    $order_total += $line_total;

    $order_item = OrderItem::create([
      'type' => 'default',
      'title' => $variation->label(),
      'purchased_entity' => $variation,
      'quantity' => (string) $quantity,
      'unit_price' => $price,
      'total_price' => new Price(number_format($line_total, 2, '.', ''), 'EUR'),
    ]);
    $order_item->save();
    $order_items[] = $order_item;
  }

  $order = Order::create([
    'type' => 'default',
    'order_number' => $order_number,
    'uid' => 0,
    'mail' => 'cliente' . $i . '@example.local',
    'store_id' => $store->id(),
    'order_items' => $order_items,
    'state' => 'completed',
    'placed' => $placed,
    'created' => $placed,
    'changed' => $placed,
    'total_price' => new Price(number_format($order_total, 2, '.', ''), 'EUR'),
  ]);
  $order->save();
  $created_orders++;
  }
}

\Drupal::service('cache_tags.invalidator')->invalidateTags([
  'commerce_order_list',
  'commerce_store_list',
  'node_list',
  'user_list',
]);

printf(
  "Management seed complete. Stores: %d. Employees: %d. Shifts created: %d. Orders created: %d.\n",
  count($created_stores),
  count($employee_users),
  $created_shifts,
  $created_orders,
);
