<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_ai_recommender\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\sweet_pineapple_management\Service\StoreInsights;

/**
 * Prepara datos de catálogo y puntúa productos según los filtros del chat.
 */
final class ProductRecommender {

  private const FLAVOR_MAP = [
    'pistac' => 'pistacho', 'fresa' => 'fruta', 'frambuesa' => 'fruta', 'frutos rojos' => 'fruta', 'limon' => 'citricos', 'naranja' => 'citricos', 'mango' => 'fruta',
    'coco' => 'coco', 'vainilla' => 'vainilla', 'cafe' => 'cafe', 'tiramis' => 'cafe', 'avellana' => 'frutos secos', 'almendra' => 'frutos secos', 'nuez' => 'frutos secos',
    'dulce de leche' => 'caramelo', 'lotus' => 'caramelo', 'caramel' => 'caramelo', 'toffee' => 'caramelo', 'canela' => 'especias', 'oreo' => 'galleta', 'cookie' => 'galleta',
    'hippo' => 'chocolate', 'choc' => 'chocolate', 'cacao' => 'chocolate', 'brownie' => 'chocolate', 'red velvet' => 'vainilla', 'clasica' => 'clasico', 'clasico' => 'clasico', 'dubai' => 'premium',
  ];
  private const TYPE_SUMMARY = [
    'tarta' => 'Tarta ideal para celebraciones',
    'megacookie' => 'Formato grande y muy compartible',
    'cookie' => 'Capricho rápido con mucho sabor',
    'brownie' => 'Opción intensa para compartir',
    'mini' => 'Formato pequeño para regalar o picar',
    'bolleria' => 'Bollería dulce para compartir',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly AliasManagerInterface $aliasManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly StoreInsights $storeInsights,
  ) {}

  /**
   * Devuelve productos ordenados por afinidad con las preferencias.
   */
  public function getRecommendations(array $preferences, int $limit = 3): array {
    $matches = $this->getCatalogItems();
    $store_context = $this->buildStoreContext($this->storeIdFromPreferences($preferences));

    foreach ($matches as &$item) {
      $item['score'] = $this->scoreProduct($item, $preferences, $store_context);
    }
    unset($item);

    $matches = array_values(array_filter($matches, static fn(array $item): bool => $item['score'] > 0));
    usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    return array_slice($matches, 0, $limit);
  }

  /**
   * Expone tiendas al recomendador sin duplicar la lógica de gestión.
   */
  public function getStoreOptions(): array {
    return $this->storeInsights->getStoreOptions();
  }

  /**
   * Obtiene el nombre visible de una tienda.
   */
  public function getStoreLabel(?int $store_id): string {
    return $this->storeInsights->getStoreLabel($store_id);
  }

  /**
   * Detecta tiendas mencionadas en texto libre.
   */
  public function findStoreIdByText(string $text): ?int {
    return $this->storeInsights->findStoreIdByText($text);
  }

  /**
   * Convierte productos Commerce en tarjetas ligeras para frontend.
   */
  public function getCatalogItems(int $limit = 200): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('product_id', 'ASC')
      ->range(0, $limit)
      ->execute();
    $products = $storage->loadMultiple($ids);
    $items = [];

    foreach ($products as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }

      $items[] = $this->buildProductData($product);

    }

    return $items;
  }

  /**
   * Prioriza productos populares y completa con catálogo si faltan.
   */
  public function getFeaturedProducts(int $limit = 4): array {
    $featured = [];
    $top_products = $this->storeInsights->getTopProductsForStore(NULL, 365, $limit);
    $product_ids = array_map(static fn(array $product): int => (int) $product['product_id'], $top_products);

    if ($product_ids !== []) {
      $storage = $this->entityTypeManager->getStorage('commerce_product');
      foreach ($storage->loadMultiple($product_ids) as $product) {
        if (!$product instanceof ProductInterface) {
          continue;
        }

        $featured[] = $this->buildProductData($product);
      }
    }

    if (count($featured) < $limit) {
      foreach ($this->getCatalogItems($limit * 2) as $item) {
        if (in_array((int) $item['id'], array_column($featured, 'id'), TRUE)) {
          continue;
        }

        $featured[] = $item;
        if (count($featured) >= $limit) {
          break;
        }
      }
    }

    return array_slice($featured, 0, $limit);
  }

  private function buildProductData(ProductInterface $product): array {
    $translation = $this->entityRepository->getTranslationFromContext($product);
    $title = $translation->label();
    $profile_text = $title;
    if ($translation->hasField('body') && !$translation->get('body')->isEmpty()) {
      $profile_text .= ' ' . strip_tags((string) $translation->get('body')->value);
    }
    $profile = $this->inferProfile($profile_text);
    $servings = $this->extractServings($translation);
    $price = $this->extractPrice($translation);

    return [
      'id' => (int) $translation->id(),
      'title' => $title,
      'url' => $this->aliasManager->getAliasByPath('/product/' . $translation->id()),
      'image_url' => $this->extractImageUrl($translation),
      'summary' => $this->buildSummary($profile, $servings, $price),
      'price' => $price,
      'price_label' => $this->formatPrice($price),
      'max_servings' => $servings,
      'profile' => $profile,
      'reasons' => [],
      'score' => 0,
    ];
  }

  private function inferProfile(string $title): array {
    $normalized = mb_strtolower($title);
    $profile = [
      'type' => 'dulce',
      'family' => 'tartas',
      'occasions' => ['compartir', 'capricho'],
      'flavors' => ['clasico'],
      'styles' => ['clasico'],
    ];

    // Estas reglas convierten textos del producto en atributos del recomendador.
    foreach ($this->profileRules() as $rule) {
      if ($this->containsAny($normalized, $rule['needles'])) {
        $profile = array_merge($profile, $rule['profile']);
        break;
      }
    }

    foreach (self::FLAVOR_MAP as $fragment => $flavor) {
      if (str_contains($normalized, $fragment)) {
        $profile['flavors'][] = $flavor;
      }
    }
    if ($this->containsAny($normalized, ['dubai', 'kinder', 'red velvet'])) {
      $profile['flavors'] = array_merge($profile['flavors'], ['chocolate']);
    }
    if (str_contains($normalized, 'dubai')) {
      $profile['flavors'] = array_merge($profile['flavors'], ['pistacho', 'premium']);
    }

    $profile['flavors'] = array_values(array_unique($profile['flavors']));
    $profile['styles'] = array_merge($profile['styles'], array_intersect_key(['premium' => 'premium', 'chocolate' => 'intenso'], array_flip($profile['flavors'])));
    if (str_contains($normalized, 'sin gluten') || str_contains($normalized, 'vegano') || str_contains($normalized, 'sin lactosa')) {
      $profile['styles'][] = 'especial';
    }

    $profile['styles'] = array_values(array_unique($profile['styles']));

    return $profile;
  }

  private function profileRules(): array {
    $tarta = ['type' => 'tarta', 'family' => 'tartas', 'occasions' => ['cumpleanos', 'compartir', 'regalo'], 'styles' => ['premium', 'clasico']];

    return [
      ['needles' => ['cheesecake'], 'profile' => $tarta],
      ['needles' => ['megacookie'], 'profile' => ['type' => 'megacookie', 'family' => 'cookies', 'occasions' => ['cumpleanos', 'compartir', 'capricho'], 'styles' => ['original', 'intenso']]],
      ['needles' => ['n.y cookie', 'ny cookie'], 'profile' => ['type' => 'cookie', 'family' => 'cookies', 'occasions' => ['capricho', 'regalo', 'compartir'], 'styles' => ['intenso', 'original']]],
      ['needles' => ['brownie', 'blondie'], 'profile' => ['type' => 'brownie', 'family' => 'brownies', 'occasions' => ['capricho', 'compartir'], 'styles' => ['intenso', 'clasico']]],
      ['needles' => ['cupcake', 'mini'], 'profile' => ['type' => 'mini', 'family' => 'mini', 'occasions' => ['regalo', 'capricho', 'compartir'], 'styles' => ['original', 'clasico']]],
      ['needles' => ['roll', 'brioche', 'trenza'], 'profile' => ['type' => 'bolleria', 'family' => 'bolleria', 'occasions' => ['capricho', 'compartir', 'regalo'], 'styles' => ['clasico', 'original']]],
      ['needles' => ['tarta', 'layer cake'], 'profile' => $tarta],
    ];
  }

  private function extractServings(ProductInterface $product): int {
    $servings = 0;

    foreach ($product->getVariations() as $variation) {
      if (preg_match('/(\d+)/', $variation->label(), $matches)) {
        $servings = max($servings, (int) $matches[1]);
      }
    }

    if ($servings === 0) {
      $profile = $this->inferProfile($product->label());
      return match ($profile['type']) {
        'tarta', 'megacookie' => 12,
        'mini' => 6,
        'bolleria' => 8,
        'cookie' => 4,
        default => 6,
      };
    }

    return $servings;
  }

  private function extractPrice(ProductInterface $product): ?array {
    $lowest = NULL;

    foreach ($product->getVariations() as $variation) {
      $price = $variation->getPrice();
      if ($price === NULL) {
        continue;
      }

      if ($lowest === NULL || (float) $price->getNumber() < (float) $lowest['number']) {
        $lowest = [
          'number' => $price->getNumber(),
          'currency_code' => $price->getCurrencyCode(),
        ];
      }
    }

    return $lowest;
  }

  private function formatPrice(?array $price): ?string {
    if ($price === NULL) {
      return NULL;
    }

    return $this->currencyFormatter->format($price['number'], $price['currency_code']);
  }

  private function extractImageUrl(ProductInterface $product): ?string {
    if (!$product->hasField('field_imagen') || $product->get('field_imagen')->isEmpty()) {
      return NULL;
    }

    $file = $product->get('field_imagen')->entity;
    if ($file === NULL) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

  private function buildSummary(array $profile, int $servings, ?array $price): string {
    $parts = [self::TYPE_SUMMARY[$profile['type']] ?? 'Dulce versátil para distintas ocasiones', 'hasta ' . $servings . ' porciones'];

    if ($price !== NULL) {
      $parts[] = 'desde ' . $this->formatPrice($price);
    }

    return implode(' | ', $parts);
  }

  private function scoreProduct(array &$item, array $preferences, array $store_context = []): int {
    $score = 20;
    $profile = $item['profile'];
    $excluded_flavors = is_array($preferences['excluded_flavors'] ?? NULL) ? $preferences['excluded_flavors'] : [];

    if ($excluded_flavors !== [] && array_intersect($excluded_flavors, $profile['flavors'])) {
      return 0;
    }

    // Cada filtro suma puntos si coincide con el perfil inferido del producto.
    foreach ($this->scoreRules() as $field => $rule) {
      if (!empty($preferences[$field]) && $rule['matches']($preferences[$field], $profile)) {
        $score += $rule['points'];
        $item['reasons'][] = $rule['reason'];
      }
    }

    if (!empty($preferences['people'])) {
      $people = (int) $preferences['people'];
      $difference = abs($item['max_servings'] - $people);

      if ($item['max_servings'] >= $people) {
        $score += max(10, 30 - ($difference * 2));
        $item['reasons'][] = 'Su tamaño se adapta al número de personas';
      }
      else {
        $score += max(0, 10 - ($difference * 2));
      }
    }

    if ($item['price_label'] !== NULL) {
      $item['reasons'][] = 'Disponible ' . mb_strtolower($item['price_label']) . ' como precio de partida';
    }

    $product_id = (int) $item['id'];
    if (isset($store_context['popular_products'][$product_id])) {
      $popularity = $store_context['popular_products'][$product_id];
      $rank_bonus = max(0, 18 - (($popularity['rank'] - 1) * 3));
      $score += $rank_bonus;

      if (!empty($store_context['store_label'])) {
        $item['reasons'][] = 'Popular en ' . $store_context['store_label'] . ' durante los últimos ' . $store_context['days'] . ' días';
      }
    }

    $item['reasons'] = array_values(array_unique($item['reasons']));

    return $score;
  }

  private function scoreRules(): array {
    return [
      'occasion' => ['points' => 30, 'reason' => 'Encaja con la ocasión seleccionada', 'matches' => static fn(string $value, array $profile): bool => in_array($value, $profile['occasions'], TRUE)],
      'family' => ['points' => 35, 'reason' => 'Pertenece a la familia de producto que buscas', 'matches' => static fn(string $value, array $profile): bool => $value === ($profile['family'] ?? '')],
      'flavor' => ['points' => 25, 'reason' => 'Coincide con el sabor preferido', 'matches' => static fn(string $value, array $profile): bool => in_array($value, $profile['flavors'], TRUE)],
      'style' => ['points' => 20, 'reason' => 'Tiene el estilo que estas buscando', 'matches' => static fn(string $value, array $profile): bool => in_array($value, $profile['styles'], TRUE)],
    ];
  }

  private function storeIdFromPreferences(array $preferences): ?int {
    if (!isset($preferences['store']) || $preferences['store'] === '') {
      return NULL;
    }

    $store_id = (int) $preferences['store'];
    return $store_id > 0 ? $store_id : NULL;
  }

  private function buildStoreContext(?int $store_id): array {
    if ($store_id === NULL) {
      return [];
    }

    $days = 30;
    $popular_products = [];
    $top_products = $this->storeInsights->getTopProductsForStore($store_id, $days);

    if ($top_products === []) {
      $days = 365;
      $top_products = $this->storeInsights->getTopProductsForStore($store_id, $days);
    }

    foreach ($top_products as $rank => $product) {
      $popular_products[(int) $product['product_id']] = [
        'rank' => $rank + 1,
        'quantity' => (float) $product['quantity'],
      ];
    }

    return [
      'store_id' => $store_id,
      'store_label' => $this->storeInsights->getStoreLabel($store_id),
      'days' => $days,
      'popular_products' => $popular_products,
    ];
  }

  private function containsAny(string $text, array $needles): bool {
    foreach ($needles as $needle) {
      if (str_contains($text, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
