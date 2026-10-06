<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_management\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Calcula indicadores de gestión desde pedidos de Commerce y entidades Drupal.
 */
final class DashboardMetrics {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Devuelve todos los datos que pinta el panel.
   */
  public function build(?int $store_id, int $start, int $end): array {
    $orders = $this->loadOrders($store_id, $start, $end);
    $sales_total = 0.0;
    $currency = 'EUR';
    $products = [];
    $sales_by_day = $this->emptyDailySeries($start, $end);
    $orders_by_store = [];
    $units_sold = 0.0;

    foreach ($orders as $order) {
      if ($order->hasField('total_price') && !$order->get('total_price')->isEmpty()) {
        $sales_total += (float) $order->get('total_price')->number;
        $currency = (string) $order->get('total_price')->currency_code;
      }

      $placed = (int) ($order->get('placed')->value ?? $order->getCreatedTime());
      $day = $this->dateFormatter->format($placed, 'custom', 'Y-m-d');
      if (isset($sales_by_day[$day])) {
        $sales_by_day[$day]['orders']++;
        if ($order->hasField('total_price') && !$order->get('total_price')->isEmpty()) {
          $sales_by_day[$day]['sales'] += (float) $order->get('total_price')->number;
        }
      }

      $store_label = $this->storeLabelFromOrder($order);
      $orders_by_store[$store_label] = ($orders_by_store[$store_label] ?? 0) + 1;

      foreach ($order->getItems() as $item) {
        $quantity = (float) $item->getQuantity();
        $units_sold += $quantity;
        $product_label = $this->productLabelFromOrderItem($item);
        if (!isset($products[$product_label])) {
          $products[$product_label] = [
            'label' => $product_label,
            'quantity' => 0.0,
            'revenue' => 0.0,
          ];
        }
        $products[$product_label]['quantity'] += $quantity;
        if ($item->hasField('total_price') && !$item->get('total_price')->isEmpty()) {
          $products[$product_label]['revenue'] += (float) $item->get('total_price')->number;
        }
      }
    }

    usort($products, static fn(array $a, array $b): int => $b['quantity'] <=> $a['quantity']);
    arsort($orders_by_store);

    return [
      'period' => [
        'start' => $start,
        'end' => $end,
      ],
      'currency' => $currency,
      'sales_total' => $sales_total,
      'order_count' => count($orders),
      'units_sold' => $units_sold,
      'average_order' => count($orders) > 0 ? $sales_total / count($orders) : 0.0,
      'products' => array_slice($products, 0, 8),
      'sales_by_day' => array_values($sales_by_day),
      'orders_by_store' => $orders_by_store,
      'employee_summary' => $this->employeeSummary($store_id, $start, $end),
    ];
  }

  /**
   * Carga pedidos cerrados para una tienda y periodo.
   */
  private function loadOrders(?int $store_id, int $start, int $end): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('placed', $start, '>=')
      ->condition('placed', $end, '<=');

    if ($store_id !== NULL) {
      $query->condition('store_id', $store_id);
    }

    $ids = $query->sort('placed', 'ASC')->execute();
    if ($ids === []) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * Cuenta empleados, turnos, horas y ausencias.
   */
  private function employeeSummary(?int $store_id, int $start, int $end): array {
    $user_query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    if ($store_id !== NULL) {
      $user_query->condition('field_sp_store', $store_id);
    }

    $employee_ids = $user_query->condition('roles', 'sweet_pineapple_employee')->execute();

    $shift_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'sp_shift')
      ->condition('status', 1)
      ->condition('field_sp_shift_start', $this->dateFormatter->format($start, 'custom', 'Y-m-d\TH:i:s'), '>=')
      ->condition('field_sp_shift_start', $this->dateFormatter->format($end, 'custom', 'Y-m-d\TH:i:s'), '<=');

    if ($store_id !== NULL) {
      $shift_query->condition('field_sp_store', $store_id);
    }

    $shift_ids = $shift_query->execute();
    $hours = 0.0;
    $absences = 0;

    if ($shift_ids !== []) {
      $shifts = $this->entityTypeManager->getStorage('node')->loadMultiple($shift_ids);
      foreach ($shifts as $shift) {
        if ($shift->hasField('field_sp_shift_status') && $shift->get('field_sp_shift_status')->value === 'absence') {
          $absences++;
          continue;
        }
        $start_value = strtotime((string) $shift->get('field_sp_shift_start')->value);
        $end_value = strtotime((string) $shift->get('field_sp_shift_end')->value);
        if ($start_value !== FALSE && $end_value !== FALSE && $end_value > $start_value) {
          $hours += ($end_value - $start_value) / 3600;
        }
      }
    }

    return [
      'active_employees' => count($employee_ids),
      'shifts' => count($shift_ids),
      'scheduled_hours' => $hours,
      'absences' => $absences,
    ];
  }

  /**
   * Crea una serie diaria rellena con ceros.
   */
  private function emptyDailySeries(int $start, int $end): array {
    $series = [];
    $cursor = strtotime(date('Y-m-d 00:00:00', $start));
    $last = strtotime(date('Y-m-d 00:00:00', $end));

    while ($cursor !== FALSE && $last !== FALSE && $cursor <= $last) {
      $key = $this->dateFormatter->format($cursor, 'custom', 'Y-m-d');
      $series[$key] = [
        'label' => $this->dateFormatter->format($cursor, 'custom', 'd/m'),
        'date' => $key,
        'sales' => 0.0,
        'orders' => 0,
      ];
      $cursor = strtotime('+1 day', $cursor);
    }

    return $series;
  }

  /**
   * Obtiene la tienda asociada a un pedido.
   */
  private function storeLabelFromOrder(object $order): string {
    $store = $order->getStore();
    return $store ? $store->label() : 'Sin tienda';
  }

  /**
   * Obtiene el producto real desde una linea de pedido.
   */
  private function productLabelFromOrderItem(object $item): string {
    $variation = $item->getPurchasedEntity();
    if ($variation && method_exists($variation, 'getProduct') && $variation->getProduct()) {
      return $variation->getProduct()->label();
    }

    return $item->label() ?: 'Producto sin nombre';
  }

}
