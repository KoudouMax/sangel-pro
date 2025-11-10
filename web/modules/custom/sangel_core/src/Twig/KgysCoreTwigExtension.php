<?php

namespace Drupal\sangel_core\Twig;

use Drupal\Core\Session\AccountProxyInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class KgysCoreTwigExtension extends AbstractExtension {

  protected $currentUser;

  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  public function getFunctions() {
    return [
      new TwigFunction('isInRole', [$this, 'isInRole']),
    ];
  }

  public function isInRole($roles): bool {
    $user_roles = $this->currentUser->getRoles();
    if (is_array($roles)) {
      return (bool) array_intersect($roles, $user_roles);
    }
    return in_array($roles, $user_roles);
  }

}
