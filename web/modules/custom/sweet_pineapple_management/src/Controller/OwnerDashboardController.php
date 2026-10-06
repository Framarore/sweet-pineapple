<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_management\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Markup;
use Drupal\sweet_pineapple_management\Form\DashboardFilterForm;
use Drupal\sweet_pineapple_management\Service\DashboardMetrics;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Panel de gerencia para consultar ventas, tiendas y turnos.
 */
final class OwnerDashboardController extends ControllerBase {

  public function __construct(
    private readonly DashboardMetrics $metrics,
    private readonly FormBuilderInterface $dashboardFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('sweet_pineapple_management.metrics'),
      $container->get('form_builder'),
    );
  }

  /**
   * Monta la página con filtros, KPIs y gráficas.
   */
  public function dashboard(Request $request): array {
    [$start, $end] = $this->periodFromRequest($request);
    $store_id = $request->query->get('store') !== NULL && $request->query->get('store') !== '' ? (int) $request->query->get('store') : NULL;
    $view = $this->selectedValue((string) $request->query->get('view', 'all'), ['all', 'sales', 'products', 'stores', 'team'], 'all');
    $chart_type = $this->selectedValue((string) $request->query->get('chart', 'line'), ['line', 'area', 'bar', 'donut'], 'line');
    $data = $this->metrics->build($store_id, $start, $end);

    return [
      '#attached' => ['library' => ['sweet_pineapple_management/dashboard']],
      'dashboard' => $this->container(['sp-dashboard'], [
        'filters' => $this->dashboardFormBuilder->getForm(DashboardFilterForm::class),
        'kpis' => $this->buildKpis($data),
        'summary' => $this->buildInsightSummary($data),
        'charts' => $this->container(['sp-dashboard__grid'], $this->buildChartPanels($data, $view, $chart_type)),
      ]),
    ];
  }

  /**
   * Lee fechas del filtro o usa los últimos 30 días.
   */
  private function periodFromRequest(Request $request): array {
    $today_start = strtotime('today 00:00:00') ?: time();
    $start = strtotime((string) $request->query->get('start', ''));
    $end = strtotime((string) $request->query->get('end', ''));

    return [
      $start !== FALSE ? strtotime(date('Y-m-d 00:00:00', $start)) : (strtotime('-29 days', $today_start) ?: $today_start - 2592000),
      $end !== FALSE ? strtotime(date('Y-m-d 23:59:59', $end)) : (strtotime('today 23:59:59') ?: time()),
    ];
  }

  /**
   * Crea las tarjetas resumen superiores.
   */
  private function buildKpis(array $data): array {
    $build = $this->container(['sp-dashboard__kpis']);
    $employee_count = max(1, (int) $data['employee_summary']['active_employees']);
    $absence_rate = (int) $data['employee_summary']['shifts'] > 0
      ? (((int) $data['employee_summary']['absences'] / (int) $data['employee_summary']['shifts']) * 100)
      : 0.0;

    foreach ([
      ['Ventas totales', $this->formatMoney($data['sales_total'], $data['currency'])],
      ['Pedidos', (string) $data['order_count']],
      ['Ticket medio', $this->formatMoney($data['average_order'], $data['currency'])],
      ['Unidades vendidas', number_format((float) $data['units_sold'], 0, ',', '.')],
      ['Ventas por empleado', $this->formatMoney((float) $data['sales_total'] / $employee_count, $data['currency'])],
      ['Empleados activos', (string) $data['employee_summary']['active_employees']],
      ['Ausencias', number_format($absence_rate, 1, ',', '.') . '%'],
    ] as $delta => [$label, $value]) {
      $build['kpi_' . $delta] = $this->container(['sp-kpi'], [
        'value' => ['#markup' => '<strong>' . Html::escape($value) . '</strong>'],
        'label' => ['#markup' => '<span>' . Html::escape($label) . '</span>'],
      ]);
    }
    return $build;
  }

  /**
   * Construye los paneles según la vista seleccionada.
   */
  private function buildChartPanels(array $data, string $view, string $chart_type): array {
    $panels = [];

    if ($this->showPanel($view, 'sales')) {
      $panels['daily_sales'] = $this->buildDailySalesChart($data['sales_by_day'], $data['currency'], $chart_type);
    }
    if ($this->showPanel($view, 'products')) {
      $panels['top_products'] = $this->rankingPanel('Productos más vendidos', $data['products'], 'quantity', $chart_type);
    }
    if ($this->showPanel($view, 'stores')) {
      $panels['stores'] = $this->rankingPanel('Pedidos por tienda', $this->storeRows($data['orders_by_store']), 'orders', $chart_type, 'sp-bar sp-bar--store');
    }
    if ($this->showPanel($view, 'team')) {
      $panels['employees'] = $this->buildEmployeePanel($data['employee_summary'], $chart_type);
    }

    return $panels;
  }

  /**
   * Dibuja ventas diarias como línea, área o barras.
   */
  private function buildDailySalesChart(array $series, string $currency, string $chart_type): array {
    $max = max(array_map(static fn(array $item): float => (float) $item['sales'], $series) ?: [0.0]);
    $count = max(1, count($series) - 1);
    $points = [];
    $bars = '';

    foreach ($series as $index => $item) {
      $x = round(24 + (($index / $count) * 352), 2);
      $y = round(154 - ($max > 0 ? (((float) $item['sales'] / $max) * 120) : 0), 2);
      $points[] = $x . ',' . $y;
      $bar_width = max(4, min(18, 300 / max(1, count($series))));
      $bars .= '<rect x="' . ($x - ($bar_width / 2)) . '" y="' . $y . '" width="' . $bar_width . '" height="' . (154 - $y) . '" rx="3"></rect>';
    }

    if ($chart_type === 'donut') {
      $svg = $this->donutChart($this->salesDonutItems($series), 'sales', $currency);
    }
    else {
      $svg = $chart_type === 'bar'
        ? '<svg class="sp-chart sp-chart--bars" viewBox="0 0 400 180" role="img" aria-label="Ventas por día"><g>' . $bars . '</g></svg>'
        : $this->lineSvg('Ventas por día', $points, $chart_type === 'area');
    }

    return $this->container(['sp-panel'], [
      'title' => ['#markup' => '<h2>Ventas por día</h2>'],
      'chart' => ['#markup' => Markup::create($svg)],
      'total' => ['#markup' => '<p>Máximo diario: ' . $this->formatMoney($max, $currency) . '</p>'],
    ]);
  }

  /**
   * Reutiliza el mismo HTML de barras para productos y tiendas.
   */
  private function rankingPanel(string $title, array $items, string $value_key, string $chart_type, string $bar_class = 'sp-bar'): array {
    $chart = $chart_type === 'donut'
      ? $this->donutChart($items, $value_key)
      : '<ul class="sp-bars">' . $this->barRows($items, $value_key, $bar_class) . '</ul>';

    return $this->container(['sp-panel'], [
      'title' => ['#markup' => '<h2>' . Html::escape($title) . '</h2>'],
      'list' => ['#markup' => Markup::create($chart)],
    ]);
  }

  /**
   * Resume turnos, horas y ausencias del equipo.
   */
  private function buildEmployeePanel(array $summary, string $chart_type): array {
    $rows = [
      ['label' => 'Turnos', 'value' => (float) $summary['shifts']],
      ['label' => 'Horas planificadas', 'value' => (float) $summary['scheduled_hours']],
      ['label' => 'Ausencias', 'value' => (float) $summary['absences']],
    ];
    $chart = $chart_type === 'donut'
      ? $this->donutChart($rows, 'value')
      : '<ul class="sp-bars sp-bars--compact">' . $this->barRows($rows, 'value', 'sp-bar sp-bar--team') . '</ul>';

    return $this->container(['sp-panel', 'sp-panel--employees'], [
      'title' => ['#markup' => '<h2>Empleados y horarios</h2>'],
      'content' => [
        '#markup' => sprintf(
          '<dl class="sp-employee-stats"><div><dt>Turnos</dt><dd>%d</dd></div><div><dt>Horas planificadas</dt><dd>%s</dd></div><div><dt>Ausencias</dt><dd>%d</dd></div></dl>',
          (int) $summary['shifts'],
          number_format((float) $summary['scheduled_hours'], 1, ',', '.'),
          (int) $summary['absences'],
        ),
      ],
      'chart' => ['#markup' => Markup::create($chart)],
    ]);
  }

  /**
   * Convierte el resumen por tienda al formato comun de barras.
   */
  private function storeRows(array $orders_by_store): array {
    return array_map(static fn(string $label, int $orders): array => ['label' => $label, 'orders' => $orders], array_keys($orders_by_store), array_map('intval', $orders_by_store));
  }

  private function barRows(array $items, string $value_key, string $bar_class): string {
    if ($items === []) {
      return '<li class="sp-empty">Sin datos en el periodo.</li>';
    }

    $max = max(array_map(static fn(array $item): float => (float) $item[$value_key], $items));
    $rows = '';
    foreach ($items as $item) {
      $value = (float) $item[$value_key];
      $width = $max > 0 ? ($value / $max) * 100 : 0;
      $rows .= '<li><span class="sp-bar__label">' . Html::escape((string) $item['label']) . '</span><span class="' . $bar_class . '"><span style="width:' . round($width, 2) . '%"></span></span><strong>' . number_format($value, 0, ',', '.') . '</strong></li>';
    }
    return $rows;
  }

  private function buildInsightSummary(array $data): array {
    $best_product = $data['products'][0]['label'] ?? 'Sin datos';
    $best_store = array_key_first($data['orders_by_store']) ?: 'Sin datos';

    return $this->container(['sp-dashboard__summary'], [
      'product' => ['#markup' => '<p><strong>Producto destacado:</strong> ' . Html::escape((string) $best_product) . '</p>'],
      'store' => ['#markup' => '<p><strong>Tienda con más pedidos:</strong> ' . Html::escape((string) $best_store) . '</p>'],
    ]);
  }

  private function lineSvg(string $label, array $points, bool $area): string {
    $point_string = implode(' ', $points);
    $area_points = '24,154 ' . $point_string . ' 376,154';
    $area_markup = $area ? '<polygon points="' . $area_points . '"></polygon>' : '';

    return '<svg class="sp-chart sp-chart--line' . ($area ? ' sp-chart--area' : '') . '" viewBox="0 0 400 180" role="img" aria-label="' . Html::escape($label) . '"><g class="sp-chart__grid"><line x1="24" y1="34" x2="376" y2="34"></line><line x1="24" y1="94" x2="376" y2="94"></line><line x1="24" y1="154" x2="376" y2="154"></line></g>' . $area_markup . '<polyline points="' . $point_string . '" /></svg>';
  }

  private function donutChart(array $items, string $value_key, ?string $currency = NULL): string {
    if ($items === []) {
      return '<p class="sp-empty">Sin datos en el periodo.</p>';
    }

    $total = array_sum(array_map(static fn(array $item): float => (float) $item[$value_key], $items));
    if ($total <= 0) {
      return '<p class="sp-empty">Sin datos en el periodo.</p>';
    }

    $colors = ['#b24728', '#557a68', '#d66b3d', '#7f5f8f', '#e2aa3b', '#3e6f8f', '#a24e66', '#6f6a57'];
    $offset = 25;
    $segments = '';
    $legend = '';

    foreach (array_values($items) as $index => $item) {
      $value = (float) $item[$value_key];
      $percent = ($value / $total) * 100;
      $color = $colors[$index % count($colors)];
      $segments .= '<circle r="42" cx="50" cy="50" pathLength="100" style="stroke:' . $color . ';stroke-dasharray:' . round($percent, 2) . ' ' . round(100 - $percent, 2) . ';stroke-dashoffset:' . round($offset, 2) . '"></circle>';
      $legend .= '<li><span class="sp-donut__swatch" style="background:' . $color . '"></span><span class="sp-donut__label">' . Html::escape((string) $item['label']) . '</span><strong>' . Html::escape($this->formatChartValue($value, $currency)) . '</strong><small>' . number_format($percent, 1, ',', '.') . '%</small></li>';
      $offset -= $percent;
    }

    return '<div class="sp-donut"><svg viewBox="0 0 100 100" role="img" aria-label="Distribución"><g>' . $segments . '</g></svg><ul>' . $legend . '</ul></div>';
  }

  private function salesDonutItems(array $series): array {
    $weeks = [];
    $week_index = 1;

    foreach (array_values($series) as $index => $item) {
      if ($index > 0 && $index % 7 === 0) {
        $week_index++;
      }

      $label = 'Semana ' . $week_index;
      if (!isset($weeks[$label])) {
        $weeks[$label] = [
          'label' => $label,
          'sales' => 0.0,
        ];
      }

      $weeks[$label]['sales'] += (float) $item['sales'];
    }

    return array_values(array_filter($weeks, static fn(array $item): bool => $item['sales'] > 0));
  }

  private function formatChartValue(float $value, ?string $currency): string {
    return $currency !== NULL
      ? $this->formatMoney($value, $currency)
      : number_format($value, 0, ',', '.');
  }

  private function selectedValue(string $value, array $allowed, string $fallback): string {
    return in_array($value, $allowed, TRUE) ? $value : $fallback;
  }

  private function showPanel(string $view, string $panel): bool {
    return $view === 'all' || $view === $panel;
  }

  private function container(array $classes, array $children = []): array {
    return ['#type' => 'container', '#attributes' => ['class' => $classes]] + $children;
  }

  private function formatMoney(float $amount, string $currency): string {
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
  }

}
