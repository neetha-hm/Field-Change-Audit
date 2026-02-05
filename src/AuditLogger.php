<?php

namespace Drupal\field_change_audit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Logs field-level changes for entities.
 */
class AuditLogger {
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
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;
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
   *   The current user service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('field_change_audit');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Logs field-level changes between entity revisions.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The updated entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $original
   *   The original entity before update.
   */
  public function logChanges(ContentEntityInterface $entity, ContentEntityInterface $original) {
    $fields = array_keys($entity->getFieldDefinitions());
    $excluded_fields = [
      'vid',
      'revision_timestamp',
      'changed',
      'revision_uid',
      'uid',
      'created',
      'path',
      'comment',
      'revision_translation_affected',
    ];

    $content_fields = array_diff($fields, $excluded_fields);

    $uid = $this->currentUser->id();
    foreach ($content_fields as $field_name) {
      if ($entity->hasField($field_name)) {
        $field_definition = $entity->getFieldDefinition($field_name);
        $field_type = $field_definition->getType();
        // Get human-readable label.
        $field_label = $field_definition->getLabel();

        // Handle paragraph fields separately.
        if ($field_type === 'entity_reference_revisions' && $field_definition->getSetting('target_type') === 'paragraph') {
          $this->logParagraphFieldChanges($entity, $original, $field_name, $field_label, $uid);
        }
        else {
          $original_value = $this->getFieldValueAsString($original, $field_name);
          $new_value = $this->getFieldValueAsString($entity, $field_name);

          $original_normalized = $this->normalizeValue($original_value);
          $new_normalized = $this->normalizeValue($new_value);
          if ($original_normalized !== $new_normalized) {
            $diff = $this->computeFieldDiff($original_value, $new_value);
            if (!empty($diff)) {
              $revision_id = $entity->get('vid')->value;
              $this->database->insert('field_change_audit_log')
                ->fields([
                  'entity_type' => $entity->getEntityTypeId(),
                  'entity_id' => $entity->id(),
                  'revision_id' => $revision_id,
                  // Use label instead of machine name.
                  'field_name' => $field_label,
                  'diff' => $diff,
                  'changed' => time(),
                  'uid' => $uid,
                ])
                ->execute();
            }
          }
        }
      }
    }
  }

  /**
   * Logs changes for paragraph reference fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The updated parent entity.
   * @param \Drupal\Core\Entity\ContentEntityInterface $original
   *   The original parent entity.
   * @param string $field_name
   *   The paragraph field machine name.
   * @param string $field_label
   *   The human-readable field label.
   * @param int $uid
   *   The user ID performing the update.
   */
  protected function logParagraphFieldChanges(ContentEntityInterface $entity, ContentEntityInterface $original, $field_name, $field_label, $uid) {
    $original_items = $original->hasField($field_name) ? $original->get($field_name)->getValue() : [];
    $new_items = $entity->hasField($field_name) ? $entity->get($field_name)->getValue() : [];

    $original_summaries = [];
    $new_summaries = [];

    // Build summaries for original paragraphs.
    foreach ($original_items as $index => $item) {
      if (!empty($item['target_id'])) {
        $paragraph = $this->loadParagraph($item);
        if ($paragraph) {
          $field_summaries = $this->getParagraphFieldSummary($paragraph, TRUE);
          $original_summaries[$item['target_id']] = [
            'summaries' => $field_summaries,
            'index' => $index,
          ];
        }
      }
    }

    // Build summaries for new paragraphs.
    foreach ($new_items as $index => $item) {
      if (!empty($item['target_id'])) {
        $paragraph = $this->loadParagraph($item);
        if ($paragraph) {
          $field_summaries = $this->getParagraphFieldSummary($paragraph, TRUE);
          $new_summaries[$item['target_id']] = [
            'summaries' => $field_summaries,
            'index' => $index,
          ];
        }
      }
    }

    $diffs = [];
    // Compare fields for matching paragraphs.
    foreach ($new_summaries as $target_id => $new_data) {
      if (isset($original_summaries[$target_id])) {
        $new_field_summaries = $new_data['summaries'];
        $original_field_summaries = $original_summaries[$target_id]['summaries'];

        // Compare each field individually.
        foreach ($new_field_summaries as $subfield_name => $new_summary) {
          $original_summary = $original_field_summaries[$subfield_name] ?? '';
          $original_normalized = $this->normalizeValue($original_summary);
          $new_normalized = $this->normalizeValue($new_summary);
          if ($original_normalized !== $new_normalized) {
            /** @var \Drupal\Core\Entity\ContentEntityInterface|null $paragraph */
            $paragraph = $this->loadParagraph(['target_id' => $target_id]);
            $subfield_label = $paragraph && $paragraph->hasField($subfield_name)
              ? $paragraph->getFieldDefinition($subfield_name)->getLabel()
              : $subfield_name;
            $diff = $this->computeFieldDiff($original_summary, $new_summary);
            if (!empty($diff)) {
              $diffs[] = "Paragraph ID $target_id, Field $subfield_label:\n$diff";
            }
          }
        }
      }
    }

    // Log deleted paragraphs.
    foreach ($original_summaries as $target_id => $original_data) {
      if (!isset($new_summaries[$target_id])) {
        $diffs[] = "Paragraph ID $target_id: Deleted";
      }
    }

    // Log added paragraphs.
    foreach ($new_summaries as $target_id => $new_data) {
      if (!isset($original_summaries[$target_id])) {
        $summary = implode("\n", array_map(function ($subfield_name, $value) use ($target_id) {
          /** @var \Drupal\Core\Entity\ContentEntityInterface|null $paragraph */
          $paragraph = $this->loadParagraph(['target_id' => $target_id]);
          $subfield_label = $paragraph && $paragraph->hasField($subfield_name)
            ? $paragraph->getFieldDefinition($subfield_name)->getLabel()
            : $subfield_name;
          return "$subfield_label: $value";
        }, array_keys($new_data['summaries']), $new_data['summaries']));
        $diffs[] = "Paragraph ID $target_id: Added\n$summary";
      }
    }

    // Log only if there are actual changes.
    if (!empty($diffs)) {
      $revision_id = $entity->get('vid')->value;
      $this->database->insert('field_change_audit_log')
        ->fields([
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => $entity->id(),
          'revision_id' => $revision_id,
          'field_name' => $field_label,
          'diff' => implode("\n\n", $diffs),
          'changed' => time(),
          'uid' => $uid,
        ])
        ->execute();
    }
  }

  /**
   * Loads a paragraph entity or revision.
   *
   * @param array $item
   *   Paragraph reference item array.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The loaded paragraph entity or NULL.
   */
  protected function loadParagraph(array $item) {

    $paragraph = NULL;

    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('paragraph');

    // Load specific revision first.
    if (!empty($item['target_revision_id'])) {
      try {
        $paragraph = $storage->loadRevision($item['target_revision_id']);
      }
      catch (\Exception $e) {
        $this->logger->warning(
          'Failed to load paragraph revision @revision_id: @error',
          [
            '@revision_id' => $item['target_revision_id'],
            '@error' => $e->getMessage(),
          ]
              );
      }
    }

    // Fallback to normal entity.
    if (!$paragraph && !empty($item['target_id'])) {
      try {
        $paragraph = $storage->load($item['target_id']);
      }
      catch (\Exception $e) {
        $this->logger->warning(
          'Failed to load paragraph @target_id: @error',
          [
            '@target_id' => $item['target_id'],
            '@error' => $e->getMessage(),
          ]
              );
      }
    }

    return $paragraph;
  }

  /**
   * Converts a field value to a normalized string.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity containing the field.
   * @param string $field_name
   *   The field machine name.
   *
   * @return string
   *   The normalized field value.
   */
  protected function getFieldValueAsString(ContentEntityInterface $entity, $field_name) {
    $output = [];

    if (!$entity->hasField($field_name)) {
      return '';
    }

    $field = $entity->get($field_name);
    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();
    $items = $field->getValue();

    foreach ($items as $index => $item) {
      switch ($field_type) {
        case 'string':
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $value = $item['value'] ?? '';
          $output[$index] = trim($value);
          break;

        case 'boolean':
          $output[$index] = !empty($item['value']) ? 'Yes' : 'No';
          break;

        case 'integer':
        case 'timestamp':
          $value = (int) ($item['value'] ?? 0);
          $output[$index] = date('Y-m-d H:i:s', $value);
          break;

        case 'decimal':
        case 'float':
          $value = (float) ($item['value'] ?? 0);
          $output[$index] = sprintf('%.2f', $value);
          break;

        case 'date':
          $output[$index] = $item['value'] ?? '';
          break;

        case 'link':
          $uri = $item['uri'] ?? '';
          $title = $item['title'] ?? '';
          $output[$index] = "$uri ($title)";
          break;

        case 'comment':
          $output[$index] = (string) ($item['status'] ?? 0);
          break;

        case 'file':
        case 'image':
          if (!empty($item['target_id'])) {
            try {
              /** @var \Drupal\file\FileInterface|null $file */
              $file = $this->entityTypeManager->getStorage('file')->load($item['target_id']);
              if ($file) {
                $uri = $file->getFileUri();
                $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
                $output[$index] = $url;
              }
              else {
                $output[$index] = 'File (deleted)';
              }
            }
            catch (\Exception $e) {
              $this->logger->warning(
                'Failed to load file @target_id: @error',
                [
                  '@target_id' => $item['target_id'],
                  '@error' => $e->getMessage(),
                ]
                          );
              $output[$index] = 'File (error)';
            }
          }
          break;

        case 'entity_reference':
        case 'entity_reference_revisions':
          $target_type = $field_definition->getSetting('target_type');
          if ($target_type === 'paragraph') {
            $output[$index] = "Paragraph ID: {$item['target_id']}";
          }
          else {
            $canonical_item = $this->canonicalizeItem($item);
            $output[$index] = json_encode($canonical_item, JSON_UNESCAPED_SLASHES);
          }
          break;

        default:
          $canonical_item = $this->canonicalizeItem($item);
          $output[$index] = json_encode($canonical_item, JSON_UNESCAPED_SLASHES);
          break;
      }
    }

    sort($output);
    return implode(', ', array_filter($output));
  }

  /**
   * Builds a summary of paragraph field values.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $paragraph
   *   The paragraph entity.
   * @param bool $as_array
   *   Whether to return the summary as an array.
   *
   * @return array|string
   *   The paragraph field summary.
   */
  protected function getParagraphFieldSummary(ContentEntityInterface $paragraph, $as_array = FALSE) {
    $summary = [];

    foreach ($paragraph->getFields() as $field_name => $field) {
      $definition = $field->getFieldDefinition();

      if (
        $definition->isComputed() ||
        $definition->isReadOnly() ||
        in_array($field_name, [
          'revision_id',
          'parent_id',
          'parent_type',
          'parent_field_name',
          'default_langcode',
          'behavior_settings',
          'created',
          'langcode',
          'revision_default',
          'status',
        ])
      ) {
        continue;
      }

      $field_type = $definition->getType();
      $values = [];
      foreach ($field->getValue() as $item) {
        switch ($field_type) {
          case 'text':
          case 'text_long':
          case 'text_with_summary':
            $value = is_array($item) && isset($item['value']) ? $item['value'] : (is_scalar($item) ? $item : '');
            if (is_scalar($value) && strlen($value) > 0) {
              $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
              $value = preg_replace('/\s+/', ' ', $value);
              if (!empty($value)) {
                $values[] = $value;
              }
            }
            break;

          case 'string':
            $value = is_array($item) && isset($item['value']) ? $item['value'] : (is_scalar($item) ? $item : '');
            if (is_scalar($value) && strlen($value) > 0) {
              $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
              $value = preg_replace('/\s+/', ' ', $value);
              if (!empty($value)) {
                $values[] = $value;
              }
            }
            break;

          case 'boolean':
            $values[] = !empty($item['value']) ? 'Yes' : 'No';
            break;

          case 'entity_reference':
          case 'entity_reference_revisions':
            if (!empty($item['target_id'])) {
              $values[] = 'Entity ID: ' . $item['target_id'];
            }
            break;

          default:
            if (is_scalar($item)) {
              $values[] = (string) $item;
            }
            elseif (is_array($item)) {
              $canonical_item = $this->canonicalizeItem($item);
              $values[] = json_encode($canonical_item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            break;
        }

        $this->logger->debug(
          'Field @name raw value: @value, normalized: @normalized',
          [
            '@name' => $field_name,
            '@value' => json_encode($item),
            '@normalized' => !empty($values) ? end($values) : '',
          ]
        );
      }

      if (!empty($values)) {
        sort($values);
        $summary[$field_name] = implode(', ', array_filter($values));
      }
    }

    if ($as_array) {
      return $summary;
    }

    ksort($summary);
    return implode("\n", array_map(function ($field_name, $value) {
      return "$field_name: $value";
    }, array_keys($summary), $summary));
  }

  /**
   * Normalizes a value for comparison.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The normalized value.
   */
  protected function normalizeValue($value) {
    $value = trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $value = preg_replace('/\s+/', ' ', $value);
    if (preg_match('/^{.*}$/', $value)) {
      $decoded = json_decode($value, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $value = json_encode($this->canonicalizeItem($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
    }
    return trim($value);
  }

  /**
   * Computes a simple textual diff between values.
   *
   * @param string $original_value
   *   The original value.
   * @param string $new_value
   *   The updated value.
   *
   * @return string
   *   The formatted diff string.
   */
  protected function computeFieldDiff($original_value, $new_value) {
    $original_value = $this->normalizeValue($original_value);
    $new_value = $this->normalizeValue($new_value);

    if ($original_value !== $new_value) {
      return "Changed from: " . trim($original_value) . "\nTo: " . trim($new_value);
    }

    return '';
  }

  /**
   * Canonicalizes an array for consistent comparison.
   *
   * @param mixed $item
   *   The value to canonicalize.
   *
   * @return mixed
   *   The canonicalized value.
   */
  protected function canonicalizeItem($item) {
    if (!is_array($item)) {
      return $item;
    }
    ksort($item);
    foreach ($item as $key => $value) {
      $item[$key] = $this->canonicalizeItem($value);
    }
    // Remove empty arrays to fix Link/Comments.
    $item = array_filter($item, static function ($v) {
      return $v !== NULL && $v !== '' && $v !== [];
    });

    return $item;
  }

}
