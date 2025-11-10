<?php

namespace Drupal\sangel_core\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Ajax command to update a header counter.
 */
class UpdateCounterCommand implements CommandInterface {

  /**
   * The selector of the counter container.
   */
  protected string $selector;

  /**
   * The updated count.
   */
  protected int $count;

  /**
   * Constructs the command.
   */
  public function __construct(string $selector, int $count) {
    $this->selector = $selector;
    $this->count = $count;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'sangelUpdateCounter',
      'selector' => $this->selector,
      'count' => $this->count,
    ];
  }

}
