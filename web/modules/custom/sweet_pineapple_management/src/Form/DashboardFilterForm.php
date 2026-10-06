<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_management\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario GET para filtrar el panel de gerencia.
 */
final class DashboardFilterForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $storeEntityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'sweet_pineapple_management_dashboard_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $default_end = strtotime('today 23:59:59') ?: time();
    $default_start = strtotime('-29 days', strtotime('today 00:00:00') ?: time()) ?: ($default_end - 2592000);

    $start = strtotime((string) $request->query->get('start', ''));
    $end = strtotime((string) $request->query->get('end', ''));
    $selected_store = (string) $request->query->get('store', '');
    $selected_view = (string) $request->query->get('view', 'all');
    $selected_chart = (string) $request->query->get('chart', 'line');

    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'sp-dashboard__filters';
    $form['#action'] = Url::fromRoute('sweet_pineapple_management.dashboard')->toString();

    $form['store'] = [
      '#type' => 'select',
      '#title' => $this->t('Tienda'),
      '#options' => ['' => $this->t('Todas las tiendas')] + $this->loadStoreOptions(),
      '#default_value' => $selected_store,
    ];

    $form['view'] = [
      '#type' => 'select',
      '#title' => $this->t('Vista'),
      '#options' => [
        'all' => $this->t('Todo el panel'),
        'sales' => $this->t('Ventas diarias'),
        'products' => $this->t('Productos'),
        'stores' => $this->t('Tiendas'),
        'team' => $this->t('Equipo'),
      ],
      '#default_value' => in_array($selected_view, ['all', 'sales', 'products', 'stores', 'team'], TRUE) ? $selected_view : 'all',
    ];

    $form['chart'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo de gráfica'),
      '#options' => [
        'line' => $this->t('Línea'),
        'area' => $this->t('Área'),
        'bar' => $this->t('Barras'),
        'donut' => $this->t('Donut'),
      ],
      '#default_value' => in_array($selected_chart, ['line', 'area', 'bar', 'donut'], TRUE) ? $selected_chart : 'line',
    ];

    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('Desde'),
      '#default_value' => date('Y-m-d', $start !== FALSE ? $start : $default_start),
    ];

    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('Hasta'),
      '#default_value' => date('Y-m-d', $end !== FALSE ? $end : $default_end),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['sp-dashboard__filter-actions']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filtrar'),
      '#button_type' => 'primary',
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Restablecer'),
      '#url' => Url::fromRoute('sweet_pineapple_management.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (['store', 'view', 'chart', 'start', 'end'] as $key) {
      $value = $form_state->getValue($key);
      if ($value !== NULL && $value !== '') {
        $query[$key] = $value;
      }
    }

    $form_state->setRedirect('sweet_pineapple_management.dashboard', [], ['query' => $query]);
  }

  /**
   * Carga tiendas para el selector del filtro.
   */
  private function loadStoreOptions(): array {
    $stores = $this->storeEntityTypeManager->getStorage('commerce_store')->loadMultiple();
    $options = [];

    foreach ($stores as $store) {
      $options[(string) $store->id()] = $store->label();
    }

    asort($options);
    return $options;
  }

}
