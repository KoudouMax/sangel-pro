<?php

declare(strict_types=1);

namespace Drupal\kint;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Kint\Parser\Parser;
use Kint\Parser\PluginBeginInterface;
use Kint\Parser\PluginCompleteInterface;
use Kint\Value\Context\ContextInterface;
use Kint\Value\Context\BaseContext;
use Kint\Value\Context\ArrayContext;
use Kint\Value\Context\ClassOwnedContext;
use Kint\Value\InstanceValue;
use Kint\Value\UninitializedValue;
use Kint\Value\Representation\ContainerRepresentation;
use Kint\Value\Representation\ValueRepresentation;
use Kint\Value\AbstractValue;

/**
 * This plugin displays entities and fields more succinctly.
 */
class DrupalFieldableEntityPlugin implements PluginBeginInterface, PluginCompleteInterface {

  /**
   * The parser we're attached to.
   */
  private Parser $parser;

  /**
   * Whether the dump is currently in the plugin or not.
   *
   * This is important since we block rendering FieldItemList outside of the
   * plugin and need to explicitly allow it inside.
   */
  private bool $currentlyInPlugin = FALSE;

  /**
   * {@inheritDoc}
   */
  public function setParser(Parser $p): void {
    $this->parser = $p;
  }

  /**
   * {@inheritDoc}
   *
   * @return string[]
   *   List of gettype types this plugin will operate on.
   */
  public function getTypes(): array {
    return ['object'];
  }

  /**
   * {@inheritDoc}
   */
  public function getTriggers(): int {
    return Parser::TRIGGER_SUCCESS | Parser::TRIGGER_BEGIN;
  }

  /**
   * Blacklist any FieldItemList that we're not dumping explicitly.
   *
   * Where "explicitly" means in this plugin or directly by the user at the top
   * level.
   *
   * @see BlacklistPlugin::$shallow_blacklist
   */
  public function parseBegin(&$var, ContextInterface $c): ?AbstractValue {
    if ($var instanceof FieldItemListInterface && !$this->currentlyInPlugin && $c->getDepth()) {
      $b = new InstanceValue($c, \get_class($var), \spl_object_hash($var), \spl_object_id($var));
      /* @phpstan-ignore assign.propertyType */
      $b->flags |= AbstractValue::FLAG_BLACKLIST;
      return $b;
    }

    return NULL;
  }

  /**
   * Plugin for FieldableEntity and FieldItemList.
   */
  public function parseComplete(&$var, AbstractValue $v, int $trigger): AbstractValue {
    if ($v instanceof InstanceValue) {
      if ($var instanceof FieldItemListInterface) {
        return $this->parseFieldItemList($var, $v);
      }
      elseif ($var instanceof FieldableEntityInterface) {
        return $this->parseFieldableEntity($var, $v);
      }
    }

    return $v;
  }

  /**
   * Attach field values to FieldItemList.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $list
   *   The fields primary access point.
   * @param \Kint\Value\InstanceValue $v
   *   The value as parsed by kint thus far.
   */
  private function parseFieldItemList(FieldItemListInterface $list, InstanceValue $v): InstanceValue {
    $values = $list->getValue();

    // FieldItemListInterface doesn't specify getValue returns array, but the
    // concrete ItemList class does, so we need to confirm it's an array here.
    if (!\is_array($values)) {
      $v->setChildren(NULL);
      return $v;
    }

    if (0 === \count($values)) {
      $rep = new ValueRepresentation(
        'No values',
        new UninitializedValue(new BaseContext('No values')),
        NULL,
        TRUE
      );
      $v->addRepresentation($rep, 0);
      $v->setChildren([]);
      return $v;
    }

    $cardinality = $list->getFieldDefinition()->getFieldStorageDefinition()->getCardinality();

    $ap = $v->getContext()->getAccessPath();

    $contents = [];

    if (1 === $cardinality) {
      // If something goes horribly wrong and someone implements a
      // FieldItemListInterface that returns a full array from
      // getValue but a null from first then I guess you're S.O.L.
      $properties = $list->first()?->getValue() ?? NULL;

      // ItemList::getValue calls getValue on all its contents, so in theory
      // this should always work. Since I don't know any field types that return
      // a single value from getValue we'll just ignore the other case.
      if (\is_array($properties)) {
        $properties = $this->stripHiddenPropertiesFromValue($properties);

        foreach ($properties as $name => $value) {
          $context = new ClassOwnedContext($name, $v->getClassName());
          $context->depth = $v->getContext()->getDepth() + 1;
          if (NULL !== $ap) {
            $context->access_path = $ap . '->' . $name;
          }

          $contents[] = $this->parser->parse($value, $context);
        }

        if (\count($contents)) {
          $rep = new ContainerRepresentation('Field properties', $contents, 'entity_field', TRUE);
          $v->addRepresentation($rep, 0);
          $v->setChildren($contents);
        }
      }

      return $v;
    }

    foreach ($values as $key => $value) {
      $context = new ArrayContext($key);
      $context->depth = $v->getContext()->getDepth() + 1;

      if (NULL !== $ap) {
        $context->access_path = $ap . '->getValue()[' . var_export($key, TRUE) . ']';
      }

      if (is_array($value)) {
        $value = $this->stripHiddenPropertiesFromValue($value);
      }

      $contents[$key] = $this->parser->parse($value, $context);
    }

    $rep = new ContainerRepresentation('Values', $contents, 'entity_field', TRUE);
    $v->addRepresentation($rep, 0);
    $v->setChildren(array_values($contents));

    return $v;
  }

  /**
   * Attach fields to FieldableEntity.
   */
  private function parseFieldableEntity(FieldableEntityInterface $entity, InstanceValue $v): InstanceValue {
    $ap = $v->getContext()->getAccessPath();
    $contents = [];
    $fields = $entity->getFields();

    $inNodePluginStash = $this->currentlyInPlugin;
    try {
      $this->currentlyInPlugin = TRUE;

      foreach ($fields as $name => $property) {
        $values = $property->getValue();
        if (!\is_array($values)) {
          return $v;
        }

        $context = new ClassOwnedContext($name, $v->getClassName());
        $context->depth = $v->getContext()->getDepth() + 1;
        if (NULL !== $ap) {
          if ($entity instanceof ContentEntityBase) {
            $context->access_path = $ap . '->' . $name;
          }
          else {
            $context->access_path = $ap . '->get(' . var_export($name, TRUE) . ')';
          }
        }

        $first = NULL;

        // If our field has a single value.
        if (1 === \count($values)) {
          $first = \reset($values);

          if (\is_array($first)) {
            $first = $this->stripHiddenPropertiesFromValue($first);

            // If we have one column just show the column.
            if (1 === \count($first)) {
              if ($context->access_path !== NULL) {
                $context->access_path .= '->' . \key($first);
              }
              $values = \reset($first);
              $contents[] = $this->parser->parse($values, $context);
              continue;
            }
          }
        }

        // Otherwise render all the values.
        $contents[] = $this->parser->parse($property, $context);
      }
    } finally {
      $this->currentlyInPlugin = $inNodePluginStash;
    }

    if ($contents) {
      $rep = new ContainerRepresentation('Entity Fields', $contents, 'entity_fields');
      $v->addRepresentation($rep, 0);
      $v->setChildren($contents);
    }

    return $v;
  }

  /**
   * Strip hidden properties from a value's property array.
   *
   * Drupal sometimes stores metadata and other internal information in keys
   * prefixed with '_' so we're just going to strip all of them. You can still
   * see the hidden information by clicking through the list member in the
   * properties tab if you really want to.
   *
   * @param mixed[] $value
   *   An array of properties as returned from FieldItemBase::getValue()
   *
   * @return mixed[]
   *   An array of properties with the "hidden" ones stripped out
   */
  private function stripHiddenPropertiesFromValue(array $value): array {
    foreach ($value as $key => $_) {
      if (is_string($key) && strlen($key) && $key[0] === '_') {
        unset($value[$key]);
      }
    }

    return $value;
  }

}
