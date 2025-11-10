<?php

namespace Drupal\kint;

use Kint\Twig\TwigExtension;

/**
 * Factory class for creating a TwigExtension instance with custom aliases.
 */
class TwigExtensionFactory {

  /**
   * Creates a TwigExtension instance with custom aliases.
   */
  public static function create(): TwigExtension {
    $extension = new TwigExtension();
    $aliases = $extension->getAliases();

    $aliases['kint'] = $aliases['d'];
    $aliases['kpr'] = $aliases['d'];
    $extension->setAliases($aliases);

    return $extension;
  }

}
