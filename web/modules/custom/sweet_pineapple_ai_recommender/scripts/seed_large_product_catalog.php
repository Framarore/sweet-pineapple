<?php

declare(strict_types=1);

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;

$entity_type_manager = \Drupal::entityTypeManager();
$product_storage = $entity_type_manager->getStorage('commerce_product');
$store_storage = $entity_type_manager->getStorage('commerce_store');
$file_storage = $entity_type_manager->getStorage('file');

$stores = $store_storage->loadMultiple();
$store = reset($stores);
if (!$store) {
  throw new RuntimeException('No Commerce store was found.');
}

$image_ids = range(30, 41);
$image_ids = array_values(array_filter($image_ids, static fn(int $fid): bool => $file_storage->load($fid) !== NULL));
if ($image_ids === []) {
  throw new RuntimeException('No seed product images were found.');
}

$families = [
  'Cheesecake' => ['category' => 'Tartas cheesecake', 'base' => 16.5, 'style' => 'premium'],
  'Megacookie' => ['category' => 'Megacookies para compartir', 'base' => 15.5, 'style' => 'original'],
  'N.Y Cookie' => ['category' => 'Cookies estilo Nueva York', 'base' => 14.5, 'style' => 'intenso'],
  'Brownie' => ['category' => 'Brownies y chocolate', 'base' => 13.5, 'style' => 'intenso'],
  'Blondie' => ['category' => 'Blondies y vainilla', 'base' => 13.5, 'style' => 'clasico'],
  'Tarta Cremosa' => ['category' => 'Tartas cremosas', 'base' => 17.5, 'style' => 'premium'],
  'Layer Cake' => ['category' => 'Layer cakes de celebracion', 'base' => 19.5, 'style' => 'premium'],
  'Cupcake Box' => ['category' => 'Mini dulces y regalos', 'base' => 12.5, 'style' => 'original'],
  'Roll Dulce' => ['category' => 'Bolleria dulce', 'base' => 11.5, 'style' => 'clasico'],
  'Brioche Trenzado' => ['category' => 'Bolleria para compartir', 'base' => 12.5, 'style' => 'clasico'],
];

$flavors = [
  ['label' => 'La Pistacchia', 'flavor' => 'pistacho', 'occasion' => 'regalo', 'premium' => 2.0],
  ['label' => 'Clasica Vanilla', 'flavor' => 'clasico vainilla', 'occasion' => 'compartir', 'premium' => 0.0],
  ['label' => 'Lotus Caramel', 'flavor' => 'caramelo lotus', 'occasion' => 'capricho', 'premium' => 1.0],
  ['label' => 'Chocolate Intenso', 'flavor' => 'chocolate cacao', 'occasion' => 'cumpleanos', 'premium' => 1.5],
  ['label' => 'Cookies & Cream', 'flavor' => 'galleta oreo', 'occasion' => 'compartir', 'premium' => 1.0],
  ['label' => 'Kinder Avellana', 'flavor' => 'chocolate avellana', 'occasion' => 'regalo', 'premium' => 1.5],
  ['label' => 'Red Velvet', 'flavor' => 'vainilla cacao', 'occasion' => 'cumpleanos', 'premium' => 1.0],
  ['label' => 'Lemon Pie', 'flavor' => 'limon citricos', 'occasion' => 'capricho', 'premium' => 0.5],
  ['label' => 'Fresa & Cream', 'flavor' => 'fresa fruta', 'occasion' => 'cumpleanos', 'premium' => 0.5],
  ['label' => 'Coco Mango', 'flavor' => 'coco mango fruta', 'occasion' => 'compartir', 'premium' => 1.0],
  ['label' => 'Tiramisu Cafe', 'flavor' => 'cafe tiramisu', 'occasion' => 'regalo', 'premium' => 1.5],
  ['label' => 'Almendra Canela', 'flavor' => 'almendra canela frutos secos especias', 'occasion' => 'compartir', 'premium' => 0.5],
  ['label' => 'Dulce de Leche', 'flavor' => 'dulce de leche caramelo', 'occasion' => 'capricho', 'premium' => 1.0],
  ['label' => 'Frutos Rojos', 'flavor' => 'frutos rojos fruta', 'occasion' => 'regalo', 'premium' => 1.0],
  ['label' => 'Dubai Premium', 'flavor' => 'dubai pistacho chocolate premium', 'occasion' => 'cumpleanos', 'premium' => 3.0],
];

$sizes = [
  ['label' => '6 Porciones', 'attribute' => 1, 'suffix' => '1', 'multiplier' => 1.0],
  ['label' => '8 Porciones', 'attribute' => 2, 'suffix' => '2', 'multiplier' => 1.65],
  ['label' => '12 Porciones', 'attribute' => 3, 'suffix' => '3', 'multiplier' => 2.35],
];

$created = 0;
$skipped = 0;
$image_delta = 0;

foreach ($families as $family => $family_data) {
  foreach ($flavors as $flavor_data) {
    $title = $family . ' ' . $flavor_data['label'];
    if ($product_storage->loadByProperties(['title' => $title])) {
      $skipped++;
      continue;
    }

    $base_price = $family_data['base'] + $flavor_data['premium'];
    $sku_base = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($title));
    $sku_base = trim((string) $sku_base, '-');
    $variations = [];

    foreach ($sizes as $size) {
      $price_number = number_format($base_price * $size['multiplier'], 2, '.', '');
      $sku = substr($sku_base, 0, 48) . '-' . $size['suffix'];
      $variation = ProductVariation::create([
        'type' => 'tarta',
        'sku' => $sku,
        'title' => $size['label'],
        'status' => TRUE,
        'price' => new Price($price_number, 'EUR'),
        'attribute_tamano' => ['target_id' => $size['attribute']],
      ]);
      if ($variation->hasField('field_precio')) {
        $variation->set('field_precio', new Price($price_number, 'EUR'));
      }
      if ($variation->hasField('field_sku')) {
        $variation->set('field_sku', $sku);
      }
      $variation->save();
      $variations[] = $variation;
    }

    $body = sprintf(
      '<p>Categoria: %s. Sabor principal: %s. Ocasion recomendada: %s. Estilo: %s. Producto creado para ampliar el catalogo del asesor inteligente de Sweet Pineapple con opciones reales por tamano y precio.</p>',
      $family_data['category'],
      $flavor_data['flavor'],
      $flavor_data['occasion'],
      $family_data['style'],
    );

    $product = Product::create([
      'type' => 'tarta',
      'title' => $title,
      'status' => TRUE,
      'uid' => 1,
      'stores' => [$store],
      'variations' => $variations,
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
      'field_imagen' => [
        'target_id' => $image_ids[$image_delta % count($image_ids)],
        'alt' => $title,
        'title' => $title,
      ],
    ]);
    $product->save();
    $created++;
    $image_delta++;
  }
}

\Drupal::service('cache_tags.invalidator')->invalidateTags(['commerce_product_list']);

printf("Large catalog seed complete. Created: %d. Skipped existing: %d.\n", $created, $skipped);
