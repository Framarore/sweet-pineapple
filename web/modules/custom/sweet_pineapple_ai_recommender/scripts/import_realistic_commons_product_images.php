<?php

declare(strict_types=1);

use Drupal\file\Entity\File;

$entity_type_manager = \Drupal::entityTypeManager();
$product_storage = $entity_type_manager->getStorage('commerce_product');
$file_storage = $entity_type_manager->getStorage('file');
$file_system = \Drupal::service('file_system');
$http_client = \Drupal::httpClient();

$directory = 'public://sweet-pineapple-products-realistic';
$file_system->prepareDirectory($directory, $file_system::CREATE_DIRECTORY | $file_system::MODIFY_PERMISSIONS);

$ids = $product_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 1)
  ->sort('product_id', 'ASC')
  ->execute();

$products = $product_storage->loadMultiple($ids);
$updated = 0;
$failed = [];
$failure_reasons = [];
$sources = [];

foreach ($products as $product) {
  $title = $product->label();
  $match = NULL;

  foreach (product_image_queries($title) as $query) {
    $match = commons_find_image($http_client, $query);
    if ($match !== NULL) {
      break;
    }
  }

  if ($match === NULL) {
    $failed[] = $title;
    $failure_reasons[$title] = 'no matching Commons image';
    continue;
  }

  $extension = product_image_extension($match['mime']);
  $filename = product_image_slug($title) . '.' . $extension;
  $uri = $directory . '/' . $filename;
  $realpath = $file_system->realpath($uri);

  if (!is_file((string) $realpath)) {
    try {
      $response = $http_client->get($match['url'], [
        'timeout' => 45,
      ]);
      $data = (string) $response->getBody();
    }
    catch (Throwable $exception) {
      $failed[] = $title;
      $failure_reasons[$title] = 'download failed: ' . $exception->getMessage() . ' | ' . $match['url'];
      continue;
    }
    if ($data === '') {
      $failed[] = $title;
      $failure_reasons[$title] = 'download returned empty: ' . $match['url'];
      continue;
    }
    $file_system->saveData($data, $uri, $file_system::EXISTS_REPLACE);
    usleep(400000);
  }

  $existing_files = $file_storage->loadByProperties(['uri' => $uri]);
  $file = reset($existing_files);
  if (!$file instanceof File) {
    $file = File::create([
      'uri' => $uri,
      'filename' => $filename,
      'status' => TRUE,
      'uid' => 1,
    ]);
  }
  $file->setPermanent();
  $file->save();

  if ($product->hasField('field_imagen')) {
    $product->set('field_imagen', [
      'target_id' => $file->id(),
      'alt' => $title,
      'title' => $title,
    ]);
    $product->save();
    $updated++;
  }

  $sources[$title] = [
    'commons_title' => $match['title'],
    'url' => $match['url'],
    'description_url' => $match['descriptionurl'],
  ];
}

file_put_contents(
  $file_system->realpath($directory) . DIRECTORY_SEPARATOR . 'sources.json',
  json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

\Drupal::service('cache_tags.invalidator')->invalidateTags(['commerce_product_list']);

printf("Realistic Commons product images complete. Updated: %d. Failed: %d.\n", $updated, count($failed));
if ($failed !== []) {
  printf("Failed products:\n- %s\n", implode("\n- ", $failed));
  printf("Failure details:\n");
  foreach (array_slice($failure_reasons, 0, 10, TRUE) as $failed_title => $reason) {
    printf("- %s: %s\n", $failed_title, $reason);
  }
}

function commons_find_image(object $http_client, string $query): ?array {
  $api_url = 'https://commons.wikimedia.org/w/rest.php/v1/search/page?' . http_build_query([
    'q' => $query,
    'limit' => 10,
  ]);

  try {
    $response = $http_client->get($api_url, [
      'timeout' => 25,
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  catch (Throwable) {
    return NULL;
  }

  $pages = $payload['pages'] ?? [];
  foreach ($pages as $page) {
    $thumbnail = $page['thumbnail'] ?? NULL;
    if (!is_array($thumbnail)) {
      continue;
    }
    if (!in_array($thumbnail['mimetype'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], TRUE)) {
      continue;
    }
    $title = strtolower((string) ($page['title'] ?? ''));
    $excerpt = strtolower(strip_tags((string) ($page['excerpt'] ?? '')));
    if (str_contains($title, 'category:') || str_contains($title, 'map') || str_contains($title, 'logo') || str_contains($title, 'diagram')) {
      continue;
    }
    if (str_contains($title, 'ice cream') || str_contains($excerpt, 'ice cream') || str_contains($title, 'donut')) {
      continue;
    }
    if (!str_contains($title . ' ' . $excerpt, 'cake')
      && !str_contains($title . ' ' . $excerpt, 'cookie')
      && !str_contains($title . ' ' . $excerpt, 'brownie')
      && !str_contains($title . ' ' . $excerpt, 'brioche')
      && !str_contains($title . ' ' . $excerpt, 'roll')
      && !str_contains($title . ' ' . $excerpt, 'cupcake')
      && !str_contains($title . ' ' . $excerpt, 'cheesecake')
      && !str_contains($title . ' ' . $excerpt, 'dessert')) {
      continue;
    }

    $url = commons_large_thumbnail_url((string) ($thumbnail['url'] ?? ''));
    if ($url === '') {
      continue;
    }

    return [
      'title' => (string) ($page['title'] ?? ''),
      'url' => $url,
      'descriptionurl' => 'https://commons.wikimedia.org/wiki/' . rawurlencode((string) ($page['key'] ?? $page['title'] ?? '')),
      'mime' => (string) $thumbnail['mimetype'],
    ];
  }

  return NULL;
}

function commons_large_thumbnail_url(string $url): string {
  $url = strtok($url, '?') ?: $url;
  $url = preg_replace('#/\d+px-#', '/500px-', $url);
  return is_string($url) ? $url : '';
}

function product_image_queries(string $title): array {
  $normalized = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title);
  $family = product_image_family($normalized);
  $flavor = product_image_flavor($normalized);

  $queries = [];
  $queries[] = $flavor . ' ' . $family;
  $queries[] = $flavor . ' dessert';
  $queries[] = $family . ' dessert';

  if ($family !== 'cake') {
    $queries[] = $flavor . ' cake';
  }
  if (str_contains($normalized, 'dubai')) {
    array_unshift($queries, 'pistachio chocolate dessert', 'pistachio chocolate cake');
  }
  if (str_contains($normalized, 'xurros') || str_contains($normalized, 'churros')) {
    array_unshift($queries, 'churros chocolate dessert');
  }
  if (str_contains($normalized, 'popcorn')) {
    array_unshift($queries, 'caramel popcorn cookie');
  }
  if (str_contains($normalized, 'roll')) {
    $queries[] = 'cinnamon roll';
    $queries[] = 'sweet roll pastry';
  }

  return array_values(array_unique(array_map(
    static fn(string $query): string => trim($query . ' food photograph'),
    $queries
  )));
}

function product_image_family(string $normalized): string {
  return match (TRUE) {
    str_contains($normalized, 'cheesecake') => 'cheesecake',
    str_contains($normalized, 'megacookie') => 'cookie',
    str_contains($normalized, 'n.y cookie') || str_contains($normalized, 'ny cookie') => 'cookie',
    str_contains($normalized, 'brownie') => 'brownie',
    str_contains($normalized, 'blondie') => 'blondie',
    str_contains($normalized, 'layer cake') => 'layer cake',
    str_contains($normalized, 'cupcake') => 'cupcake',
    str_contains($normalized, 'roll') => 'cinnamon roll',
    str_contains($normalized, 'brioche') => 'brioche',
    default => 'cake',
  };
}

function product_image_flavor(string $normalized): string {
  return match (TRUE) {
    str_contains($normalized, 'pistac') => 'pistachio',
    str_contains($normalized, 'clasica') || str_contains($normalized, 'vanilla') => 'vanilla',
    str_contains($normalized, 'lotus') || str_contains($normalized, 'caramel') || str_contains($normalized, 'toffee') => 'caramel',
    str_contains($normalized, 'cookies') || str_contains($normalized, 'oreo') => 'cookies and cream',
    str_contains($normalized, 'kinder') || str_contains($normalized, 'avellana') => 'hazelnut chocolate',
    str_contains($normalized, 'red velvet') => 'red velvet',
    str_contains($normalized, 'lemon') || str_contains($normalized, 'limon') => 'lemon',
    str_contains($normalized, 'fresa') || str_contains($normalized, 'strawberry') => 'strawberry',
    str_contains($normalized, 'coco') || str_contains($normalized, 'mango') => 'coconut mango',
    str_contains($normalized, 'tiramisu') || str_contains($normalized, 'cafe') => 'tiramisu coffee',
    str_contains($normalized, 'almendra') || str_contains($normalized, 'canela') => 'almond cinnamon',
    str_contains($normalized, 'dulce de leche') => 'dulce de leche',
    str_contains($normalized, 'frutos rojos') => 'berry',
    str_contains($normalized, 'dubai') => 'pistachio chocolate',
    str_contains($normalized, 'hippo') || str_contains($normalized, 'choc') || str_contains($normalized, 'cacao') => 'chocolate',
    str_contains($normalized, 'popcorn') => 'caramel popcorn',
    str_contains($normalized, 'xurros') || str_contains($normalized, 'churros') => 'churros chocolate',
    default => 'sweet bakery',
  };
}

function product_image_extension(string $mime): string {
  return match ($mime) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'jpg',
  };
}

function product_image_slug(string $title): string {
  $ascii = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title);
  $slug = preg_replace('/[^a-z0-9]+/', '-', $ascii);
  return trim((string) $slug, '-') ?: 'sweet-pineapple-product';
}
