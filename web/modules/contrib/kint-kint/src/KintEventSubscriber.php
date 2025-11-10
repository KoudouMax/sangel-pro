<?php

declare(strict_types=1);

namespace Drupal\kint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\EventSubscriber\AuthenticationSubscriber;
use Drupal\Core\Session\AccountEvents;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSetEvent;
use Kint\Kint;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets Kint::$enabled_mode based on the user permissions.
 */
class KintEventSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    ?ConfigFactoryInterface $config_factory = NULL,
  ) {
    // We already try to set this in kint.module but since
    // the container might not exist yet we'll do it here too.
    if ($config_factory) {
      \kint_initialize_kint_settings($config_factory->get('kint.settings'));
    }
  }

  /**
   * Sets mode by user permissions.
   */
  public function setModeFromPermission(AccountSetEvent $event): void {
    Kint::$enabled_mode = $event->getAccount()->hasPermission('access kint dumps');
  }

  /**
   * Sets mode for anonymous users.
   *
   * AccountEvents::SET_USER is never triggered for anonymous users,
   * so this catch-all will handle them at least once per request.
   *
   * Without this handler setting early_enable to true would allow
   * anonymous users to see all dumps.
   */
  public function setModeForAnonymous(RequestEvent $event): void {
    Kint::$enabled_mode = $this->currentUser->hasPermission('access kint dumps');
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents(): array {
    $weight = self::getAuthenticationWeight() ?? -1;

    return [
      KernelEvents::REQUEST => [
        ['setModeForAnonymous', $weight],
      ],
      AccountEvents::SET_USER => [
        'setModeFromPermission',
      ],
    ];
  }

  /**
   * Gets event handler weight of authentication handler.
   *
   * The EventSubscriberInterface docblock describes 3 return structures:
   *
   * * ['eventName' => 'methodName']
   * * ['eventName' => ['methodName', $priority]]
   * * ['eventName' => [['methodName1', $priority], ['methodName2']]]
   *
   * This checks all of them against the AuthenticationSubscriber
   */
  private static function getAuthenticationWeight(): ?int {
    // In case drupal changes the AuthenticationSubscriber to something else in
    // the future we'll just wrap a dirty try catch around it.
    try {
      $auth_events = AuthenticationSubscriber::getSubscribedEvents();
    }
    catch (\Throwable) {
      return NULL;
    }

    if (!isset($auth_events[KernelEvents::REQUEST])) {
      return NULL;
    }

    $auth_events = $auth_events[KernelEvents::REQUEST];

    if ('onKernelRequestAuthenticate' === $auth_events) {
      return 0;
    }

    if (!\is_array($auth_events)) {
      return NULL;
    }

    if (\is_string($auth_events[0])) {
      $auth_events = [$auth_events];
    }

    /** @var array<int, array{0: string, 1?: int}> $auth_events*/
    foreach ($auth_events as $listener) {
      if ('onKernelRequestAuthenticate' === $listener[0]) {
        return $listener[1] ?? 0;
      }
    }

    return NULL;
  }

}
