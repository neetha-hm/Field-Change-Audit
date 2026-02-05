<?php

namespace Drupal\field_change_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Controller for audit view.
 */
class AuditController extends ControllerBase {
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the audit controller.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Displays the field change audit table for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being viewed.
   *
   * @return array
   *   A render array containing the audit table.
   */
  public function viewAudit(NodeInterface $node) {
    $uid = $this->currentUser->id();
    $query = $this->database->select('field_change_audit_log', 'l')
      ->fields('l', ['revision_id', 'field_name', 'diff', 'changed', 'uid'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->orderBy('changed', 'DESC');

    if (!$this->currentUser->hasPermission('administer nodes')) {
      $query->condition('l.uid', $uid);
    }

    $results = $query->execute()->fetchAll();
    $user_storage = $this->entityTypeManager->getStorage('user');
    $rows = [];
    foreach ($results as $row) {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $user_storage->load($row->uid);

      $username = $user
        ? $user->getDisplayName()
        : $this->t('Unknown user (UID: @uid)', ['@uid' => $row->uid]);

      $rows[] = [
        'revision' => $row->revision_id,
        'field' => $row->field_name,
        'diff' => ['data' => $row->diff, 'style' => 'white-space: pre-wrap;'],
        'changed' => date('Y-m-d H:i:s', $row->changed),
        'user' => $username,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Revision', 'Field', 'Diff', 'Changed', 'User'],
      '#rows' => $rows,
      '#empty' => $this->t('No changes logged.'),
      '#cache' => ['max-age' => 0],
    ];

    return $build;
  }

}
