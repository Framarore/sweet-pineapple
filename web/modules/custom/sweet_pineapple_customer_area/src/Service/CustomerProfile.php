<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_customer_area\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

/**
 * Lee preferencias y pedidos del cliente actual.
 */
final class CustomerProfile {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function account(): ?UserInterface {
    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    return $account instanceof UserInterface ? $account : NULL;
  }

  public function preferences(): array {
    $account = $this->account();
    if (!$account) {
      return [];
    }

    return [
      'favorite_flavors' => $this->fieldValues($account, 'field_sp_favorite_flavors'),
      'avoided_flavors' => $this->fieldValues($account, 'field_sp_avoided_flavors'),
      'allergens' => $this->fieldValues($account, 'field_sp_allergens'),
      'occasions' => $this->fieldValues($account, 'field_sp_customer_occasions'),
      'next_event' => $account->hasField('field_sp_next_event') && !$account->get('field_sp_next_event')->isEmpty() ? (string) $account->get('field_sp_next_event')->value : '',
    ];
  }

  /**
   * Devuelve los ultimos pedidos Commerce asociados al usuario.
   */
  public function recentOrders(int $limit = 5): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->sort('placed', 'DESC')
      ->range(0, $limit)
      ->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  private function fieldValues(UserInterface $account, string $field): array {
    return $account->hasField($field) && !$account->get($field)->isEmpty()
      ? array_column($account->get($field)->getValue(), 'value')
      : [];
  }

}
