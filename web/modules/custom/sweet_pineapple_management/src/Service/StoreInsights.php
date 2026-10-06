<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_management\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Ofrece senales de negocio por tienda para otras funcionalidades.
 */
final class StoreInsights {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Resume ventas recientes de una tienda.
   */
  public function getStoreSummary(?int $store_id, int $days = 30): array {
    $orders = $this->loadRecentOrders($store_id, $days);
    $sales_total = 0.0;

    foreach ($orders as $order) {
      if ($order->hasField('total_price') && !$order->get('total_price')->isEmpty()) {
        $sales_total += (float) $order->get('total_price')->number;
      }
    }

    return [
      'store_id' => $store_id,
      'store_label' => $this->getStoreLabel($store_id),
      'days' => $days,
      'order_count' => count($orders),
      'sales_total' => $sales_total,
    ];
  }

  /**
   * Ordena productos por cantidad vendida en una tienda.
   */
  public function getTopProductsForStore(?int $store_id, int $days = 30, int $limit = 8): array {
    $products = [];

    foreach ($this->loadRecentOrders($store_id, $days) as $order) {
      foreach ($order->getItems() as $item) {
        $variation = $item->getPurchasedEntity();
        if (!$variation || !method_exists($variation, 'getProduct') || !$variation->getProduct()) {
          continue;
        }

        $product = $variation->getProduct();
        $product_id = (int) $product->id();
        if (!isset($products[$product_id])) {
          $products[$product_id] = [
            'product_id' => $product_id,
            'label' => $product->label(),
            'quantity' => 0.0,
            'revenue' => 0.0,
          ];
        }

        $products[$product_id]['quantity'] += (float) $item->getQuantity();
        if ($item->hasField('total_price') && !$item->get('total_price')->isEmpty()) {
          $products[$product_id]['revenue'] += (float) $item->get('total_price')->number;
        }
      }
    }

    usort($products, static function (array $a, array $b): int {
      $quantity_compare = $b['quantity'] <=> $a['quantity'];
      return $quantity_compare !== 0 ? $quantity_compare : ($b['revenue'] <=> $a['revenue']);
    });

    return array_slice($products, 0, $limit);
  }

  /**
   * Agrupa productos por tiendas donde se han vendido.
   */
  public function getProductStoreMap(int $days = 365): array {
    $map = [];

    foreach ($this->loadRecentOrders(NULL, $days) as $order) {
      $store = $order->getStore();
      if (!$store) {
        continue;
      }

      $store_id = (int) $store->id();
      foreach ($order->getItems() as $item) {
        $variation = $item->getPurchasedEntity();
        if (!$variation || !method_exists($variation, 'getProduct') || !$variation->getProduct()) {
          continue;
        }

        $product_id = (int) $variation->getProduct()->id();
        $map[$product_id][$store_id] = $store_id;
      }
    }

    foreach ($map as $product_id => $store_ids) {
      $map[$product_id] = array_values($store_ids);
    }

    return $map;
  }

  /**
   * Devuelve tiendas para selectores publicos.
   */
  public function getStoreOptions(): array {
    $stores = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple();
    $options = [];

    foreach ($stores as $store) {
      $options[(string) $store->id()] = $store->label();
    }

    natcasesort($options);
    return $options;
  }

  /**
   * Devuelve la etiqueta visible de una tienda.
   */
  public function getStoreLabel(?int $store_id): string {
    if ($store_id === NULL) {
      return 'Todas las tiendas';
    }

    $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
    return $store ? $store->label() : 'Tienda no encontrada';
  }

  /**
   * Detecta una tienda mencionada en texto libre.
   */
  public function findStoreIdByText(string $text): ?int {
    $normalized = $this->normalize($text);
    $ignored_words = ['sweet', 'pineapple', 'pasteleria'];

    foreach ($this->getStoreOptions() as $store_id => $label) {
      $store_label = $this->normalize((string) $label);
      if (str_contains($normalized, $store_label)) {
        return (int) $store_id;
      }

      foreach (explode(' ', $store_label) as $word) {
        if (mb_strlen($word) >= 4 && !in_array($word, $ignored_words, TRUE) && str_contains($normalized, $word)) {
          return (int) $store_id;
        }
      }
    }

    return NULL;
  }

  /**
   * Carga pedidos recientes dentro de una ventana movil.
   */
  private function loadRecentOrders(?int $store_id, int $days): array {
    $end = $this->time->getRequestTime();
    $start = strtotime('-' . max(1, $days) . ' days', $end) ?: ($end - 2592000);

    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('placed', $start, '>=')
      ->condition('placed', $end, '<=');

    if ($store_id !== NULL) {
      $query->condition('store_id', $store_id);
    }

    $ids = $query->sort('placed', 'DESC')->execute();
    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  private function normalize(string $text): string {
    $text = mb_strtolower($text);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return is_string($ascii) ? $ascii : $text;
  }

}
