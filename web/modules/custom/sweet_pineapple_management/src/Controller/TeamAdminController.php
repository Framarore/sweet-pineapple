<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_management\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Página sencilla para administrar empleados y turnos.
 */
final class TeamAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $teamEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Construye una vista operativa para altas, edición y revisión rápida.
   */
  public function overview(): array {
    return [
      '#attached' => ['library' => ['sweet_pineapple_management/dashboard']],
      'content' => $this->container(['sp-team-admin'], [
        'actions' => $this->buildActionCards(),
        'summary' => $this->buildSummary(),
        'employees' => $this->buildEmployeesPanel(),
        'shifts' => $this->buildShiftsPanel(),
      ]),
    ];
  }

  private function buildActionCards(): array {
    $actions = [
      [
        'title' => 'Añadir empleado',
        'text' => 'Crea una cuenta de usuario y asígnale tienda, puesto y rol.',
        'url' => 'internal:/admin/people/create',
        'permission' => 'administer users',
      ],
      [
        'title' => 'Añadir turno',
        'text' => 'Planifica un horario para un empleado y una tienda.',
        'url' => 'internal:/node/add/sp_shift',
        'permission' => 'create sp_shift content',
      ],
      [
        'title' => 'Administrar usuarios',
        'text' => 'Abre el listado completo de usuarios de Drupal.',
        'url' => 'internal:/admin/people',
        'permission' => 'administer users',
      ],
      [
        'title' => 'Ver todos los turnos',
        'text' => 'Filtra el contenido por el tipo Turno de empleado.',
        'url' => 'internal:/admin/content?type=sp_shift',
        'permission' => 'access content overview',
      ],
    ];

    $build = $this->container(['sp-team-admin__actions']);
    foreach ($actions as $delta => $action) {
      if (!$this->currentUser()->hasPermission($action['permission'])) {
        continue;
      }

      $build['action_' . $delta] = $this->container(['sp-team-action'], [
        'title' => ['#markup' => '<h2>' . Html::escape($action['title']) . '</h2>'],
        'text' => ['#markup' => '<p>' . Html::escape($action['text']) . '</p>'],
        'link' => Link::fromTextAndUrl($action['title'], Url::fromUri($action['url']))
          ->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]],
      ]);
    }

    return $build;
  }

  private function buildSummary(): array {
    $employee_ids = $this->employeeIds(1000);
    $shift_ids = $this->shiftIds(1000);
    $planned_ids = $this->teamEntityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'sp_shift')
      ->condition('status', 1)
      ->condition('field_sp_shift_status', 'planned')
      ->execute();

    return $this->container(['sp-dashboard__kpis', 'sp-team-admin__summary'], [
      'employees' => $this->kpi('Empleados', (string) count($employee_ids)),
      'shifts' => $this->kpi('Turnos registrados', (string) count($shift_ids)),
      'planned' => $this->kpi('Turnos planificados', (string) count($planned_ids)),
    ]);
  }

  private function buildEmployeesPanel(): array {
    $rows = [];
    $users = $this->teamEntityTypeManager->getStorage('user')->loadMultiple($this->employeeIds(20));

    foreach ($users as $account) {
      if (!$account instanceof UserInterface) {
        continue;
      }

      $can_administer_users = $this->currentUser()->hasPermission('administer users');
      $rows[] = [
        'name' => $can_administer_users
          ? Link::fromTextAndUrl($account->getDisplayName(), Url::fromUri('internal:/user/' . $account->id() . '/edit'))
          : $account->getDisplayName(),
        'mail' => $account->getEmail(),
        'store' => $this->referencedLabel($account, 'field_sp_store'),
        'position' => $this->positionLabel($account),
        'status' => $account->isActive() ? 'Activo' : 'Bloqueado',
        'operations' => $can_administer_users
          ? Link::fromTextAndUrl('Editar', Url::fromUri('internal:/user/' . $account->id() . '/edit'))
          : '-',
      ];
    }

    return $this->panel('Empleados recientes', [
      'table' => [
        '#type' => 'table',
        '#header' => ['Nombre', 'Email', 'Tienda', 'Puesto', 'Estado', 'Acciones'],
        '#rows' => $rows,
        '#empty' => 'Aún no hay empleados dados de alta.',
      ],
    ], 'sp-panel sp-team-admin__panel');
  }

  private function buildShiftsPanel(): array {
    $rows = [];
    $nodes = $this->teamEntityTypeManager->getStorage('node')->loadMultiple($this->shiftIds(20));

    foreach ($nodes as $shift) {
      if (!$shift instanceof NodeInterface) {
        continue;
      }

      $rows[] = [
        'title' => Link::fromTextAndUrl($shift->label(), Url::fromUri('internal:/node/' . $shift->id() . '/edit')),
        'store' => $this->referencedLabel($shift, 'field_sp_store'),
        'employee' => $this->referencedLabel($shift, 'field_sp_employee'),
        'start' => $this->dateValue($shift, 'field_sp_shift_start'),
        'end' => $this->dateValue($shift, 'field_sp_shift_end'),
        'status' => $this->shiftStatusLabel($shift),
        'operations' => Link::fromTextAndUrl('Editar', Url::fromUri('internal:/node/' . $shift->id() . '/edit')),
      ];
    }

    return $this->panel('Últimos turnos', [
      'table' => [
        '#type' => 'table',
        '#header' => ['Turno', 'Tienda', 'Empleado', 'Inicio', 'Fin', 'Estado', 'Acciones'],
        '#rows' => $rows,
        '#empty' => 'Aún no hay turnos registrados.',
      ],
    ], 'sp-panel sp-team-admin__panel');
  }

  private function employeeIds(int $limit): array {
    return $this->teamEntityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', 'sweet_pineapple_employee')
      ->sort('uid', 'DESC')
      ->range(0, $limit)
      ->execute();
  }

  private function shiftIds(int $limit): array {
    return $this->teamEntityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'sp_shift')
      ->sort('field_sp_shift_start', 'DESC')
      ->range(0, $limit)
      ->execute();
  }

  private function referencedLabel(object $entity, string $field): string {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '-';
    }

    $referenced = $entity->get($field)->entity;
    return $referenced ? $referenced->label() : '-';
  }

  private function positionLabel(UserInterface $account): string {
    if (!$account->hasField('field_sp_position') || $account->get('field_sp_position')->isEmpty()) {
      return '-';
    }

    $allowed = $account->get('field_sp_position')->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values');
    $value = (string) $account->get('field_sp_position')->value;

    return $allowed[$value] ?? $value;
  }

  private function shiftStatusLabel(NodeInterface $shift): string {
    if (!$shift->hasField('field_sp_shift_status') || $shift->get('field_sp_shift_status')->isEmpty()) {
      return '-';
    }

    $labels = [
      'planned' => 'Planificado',
      'completed' => 'Completado',
      'absence' => 'Ausencia',
    ];
    $value = (string) $shift->get('field_sp_shift_status')->value;

    return $labels[$value] ?? $value;
  }

  private function dateValue(NodeInterface $shift, string $field): string {
    if (!$shift->hasField($field) || $shift->get($field)->isEmpty()) {
      return '-';
    }

    $timestamp = strtotime((string) $shift->get($field)->value);
    return $timestamp !== FALSE
      ? $this->dateFormatter->format($timestamp, 'custom', 'd/m/Y H:i')
      : '-';
  }

  private function kpi(string $label, string $value): array {
    return $this->container(['sp-kpi'], [
      'value' => ['#markup' => '<strong>' . Html::escape($value) . '</strong>'],
      'label' => ['#markup' => '<span>' . Html::escape($label) . '</span>'],
    ]);
  }

  private function panel(string $title, array $children, string $classes): array {
    return $this->container(explode(' ', $classes), [
      'title' => ['#markup' => '<h2>' . Html::escape($title) . '</h2>'],
    ] + $children);
  }

  private function container(array $classes, array $children = []): array {
    return ['#type' => 'container', '#attributes' => ['class' => $classes]] + $children;
  }

}
