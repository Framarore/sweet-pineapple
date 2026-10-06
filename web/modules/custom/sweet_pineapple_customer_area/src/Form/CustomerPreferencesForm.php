<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_customer_area\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sweet_pineapple_customer_area\Service\CustomerProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario corto para editar preferencias del cliente.
 */
final class CustomerPreferencesForm extends FormBase {

  public function __construct(private readonly CustomerProfile $profile) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('sweet_pineapple_customer_area.profile'));
  }

  public function getFormId(): string {
    return 'sweet_pineapple_customer_preferences_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $defaults = $this->profile->preferences();
    $form['#attributes']['class'][] = 'sp-customer-preferences';

    foreach ($this->fields() as $key => [$title, $options]) {
      $form[$key] = [
        '#type' => 'checkboxes',
        '#title' => $this->t($title),
        '#options' => $options,
        '#default_value' => $defaults[$key] ?? [],
      ];
    }

    $form['next_event'] = [
      '#type' => 'date',
      '#title' => $this->t('Proxima fecha importante'),
      '#default_value' => $defaults['next_event'] ?? '',
    ];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Guardar preferencias'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->profile->account();
    if (!$account) {
      return;
    }

    foreach ($this->fieldMap() as $form_key => $field_name) {
      $values = array_values(array_filter((array) $form_state->getValue($form_key)));
      $account->set($field_name, array_map(static fn(string $value): array => ['value' => $value], $values));
    }
    $account->set('field_sp_next_event', $form_state->getValue('next_event') ?: NULL);
    $account->save();
    $this->messenger()->addStatus($this->t('Preferencias guardadas.'));
  }

  private function fields(): array {
    return [
      'favorite_flavors' => ['Sabores favoritos', $this->flavors()],
      'avoided_flavors' => ['Sabores a evitar', $this->flavors()],
      'allergens' => ['Alergias o restricciones', ['gluten' => 'Gluten', 'lactosa' => 'Lactosa', 'frutos_secos' => 'Frutos secos', 'huevo' => 'Huevo', 'soja' => 'Soja', 'vegano' => 'Preferencia vegana']],
      'occasions' => ['Ocasiones habituales', ['cumpleanos' => 'Cumpleaños', 'regalo' => 'Regalos', 'oficina' => 'Oficina', 'familia' => 'Familia', 'capricho' => 'Caprichos']],
    ];
  }

  private function fieldMap(): array {
    return [
      'favorite_flavors' => 'field_sp_favorite_flavors',
      'avoided_flavors' => 'field_sp_avoided_flavors',
      'allergens' => 'field_sp_allergens',
      'occasions' => 'field_sp_customer_occasions',
    ];
  }

  private function flavors(): array {
    return ['chocolate' => 'Chocolate', 'pistacho' => 'Pistacho', 'caramelo' => 'Caramelo/Lotus', 'fruta' => 'Fruta', 'citricos' => 'Citricos', 'cafe' => 'Cafe', 'galleta' => 'Galleta', 'clasico' => 'Clasico'];
  }

}
