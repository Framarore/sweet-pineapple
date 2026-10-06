<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_customer_area\Controller;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\sweet_pineapple_customer_area\Form\CustomerPreferencesForm;
use Drupal\sweet_pineapple_customer_area\Service\CustomerProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Construye el area privada del cliente.
 */
final class CustomerAreaController extends ControllerBase {

  public function __construct(
    private readonly CustomerProfile $profile,
    private readonly FormBuilderInterface $customerFormBuilder,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly CurrencyFormatter $currencyFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('sweet_pineapple_customer_area.profile'),
      $container->get('form_builder'),
      $container->get('date.formatter'),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  public function dashboard(): array {
    $account = $this->profile->account();
    $preferences = $this->profile->preferences();

    return [
      '#attached' => ['library' => ['sweet_pineapple_customer_area/customer_area']],
      '#cache' => ['contexts' => ['user']],
      'page' => $this->container('sp-customer-area', [
        'hero' => ['#markup' => '<section class="sp-customer-hero"><p>Area privada</p><h1>Hola, ' . Html::escape($account?->getDisplayName() ?? 'cliente') . '</h1><a href="/tienda">Volver a la tienda</a></section>'],
        'grid' => $this->container('sp-customer-grid', [
          'summary' => $this->card('Tu perfil dulce', $this->preferenceSummary($preferences)),
          'orders' => $this->card('Ultimos pedidos', $this->ordersHtml()),
          'preferences' => $this->container('sp-customer-card sp-customer-card--wide', [
            'title' => ['#markup' => '<h2>Preferencias para personalizar la experiencia</h2>'],
            'form' => $this->customerFormBuilder->getForm(CustomerPreferencesForm::class),
          ]),
        ]),
      ]),
    ];
  }

  private function preferenceSummary(array $preferences): string {
    $items = [
      'Favoritos' => $preferences['favorite_flavors'],
      'Evitar' => $preferences['avoided_flavors'],
      'Restricciones' => $preferences['allergens'],
      'Ocasiones' => $preferences['occasions'],
    ];
    $html = '';
    foreach ($items as $label => $values) {
      $html .= '<dt>' . Html::escape($label) . '</dt><dd>' . ($values ? Html::escape(implode(', ', $values)) : 'Sin definir') . '</dd>';
    }
    if (!empty($preferences['next_event'])) {
      $html .= '<dt>Proxima fecha</dt><dd>' . Html::escape($preferences['next_event']) . '</dd>';
    }
    $flavor = $preferences['favorite_flavors'][0] ?? '';
    $url = '/tienda/recomendador' . ($flavor ? '?flavor=' . rawurlencode($flavor) : '');
    return '<dl class="sp-customer-list">' . $html . '</dl><a class="sp-customer-link" href="' . Html::escape($url) . '">Abrir recomendador</a>';
  }

  private function ordersHtml(): string {
    $rows = '';
    foreach ($this->profile->recentOrders() as $order) {
      $total = $order->getTotalPrice();
      $price = $total ? $this->currencyFormatter->format($total->getNumber(), $total->getCurrencyCode()) : '-';
      $placed = (int) ($order->get('placed')->value ?? $order->getCreatedTime());
      $rows .= '<li><strong>' . Html::escape($order->label()) . '</strong><span>' . $this->dateFormatter->format($placed, 'custom', 'd/m/Y') . '</span><span>' . Html::escape($price) . '</span></li>';
    }
    return $rows === '' ? '<p class="sp-customer-empty">Todavía no hay pedidos asociados a esta cuenta.</p>' : '<ul class="sp-customer-orders">' . $rows . '</ul>';
  }

  private function card(string $title, string $html): array {
    return $this->container('sp-customer-card', ['title' => ['#markup' => '<h2>' . Html::escape($title) . '</h2>'], 'content' => ['#markup' => $html]]);
  }

  private function container(string $classes, array $children): array {
    return ['#type' => 'container', '#attributes' => ['class' => explode(' ', $classes)]] + $children;
  }

}
