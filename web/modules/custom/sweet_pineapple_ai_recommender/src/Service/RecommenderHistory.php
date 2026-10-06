<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_ai_recommender\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\user\UserDataInterface;

final class RecommenderHistory {

  private const LIMIT = 20;
  private const MODULE = 'sweet_pineapple_ai_recommender';
  private const KEY = 'conversation_history';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly UserDataInterface $userData,
    private readonly PrivateTempStoreFactory $privateTempStoreFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Devuelve solo los campos que necesita el chat del navegador.
   */
  public function getHistory(): array {
    $history = $this->load();

    return array_map(
      static fn(array $item): array => [
        'role' => $item['role'],
        'content' => $item['content'],
      ],
      $history,
    );
  }

  /**
   * Guarda el ultimo turno y mantiene el historial acotado.
   */
  public function append(string $user_message, string $assistant_reply): void {
    $history = $this->load();
    $timestamp = $this->time->getRequestTime();

    $history[] = [
      'role' => 'user',
      'content' => $this->cleanMessage($user_message),
      'created' => $timestamp,
    ];
    $history[] = [
      'role' => 'assistant',
      'content' => $this->cleanMessage($assistant_reply),
      'created' => $timestamp,
    ];

    $history = array_slice($history, -self::LIMIT);
    $this->save($history);
  }

  /**
   * Usa user_data para usuarios con sesion y tempstore para anonimos.
   */
  private function load(): array {
    $history = $this->isAuthenticated()
      ? $this->userData->get(self::MODULE, (int) $this->currentUser->id(), self::KEY)
      : $this->privateTempStoreFactory->get(self::MODULE)->get(self::KEY);

    return is_array($history) ? $this->normalizeHistory($history) : [];
  }

  /**
   * Persiste el historial en el almacen adecuado.
   */
  private function save(array $history): void {
    if ($this->isAuthenticated()) {
      $this->userData->set(self::MODULE, (int) $this->currentUser->id(), self::KEY, $history);
      return;
    }

    $this->privateTempStoreFactory->get(self::MODULE)->set(self::KEY, $history);
  }

  /**
   * Elimina entradas incompletas o inseguras antes de devolverlas.
   */
  private function normalizeHistory(array $history): array {
    $items = [];

    foreach ($history as $item) {
      if (!is_array($item) || !in_array(($item['role'] ?? ''), ['user', 'assistant'], TRUE)) {
        continue;
      }

      $content = $this->cleanMessage((string) ($item['content'] ?? ''));
      if ($content === '') {
        continue;
      }

      $items[] = [
        'role' => (string) $item['role'],
        'content' => $content,
        'created' => (int) ($item['created'] ?? 0),
      ];
    }

    return array_slice($items, -self::LIMIT);
  }

  private function cleanMessage(string $message): string {
    return mb_substr(trim(strip_tags($message)), 0, 600);
  }

  private function isAuthenticated(): bool {
    return !$this->currentUser->isAnonymous();
  }

}
