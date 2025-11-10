<?php

namespace Drupal\sangel_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects commercial users to the gestion dashboard after login.
 */
final class CommercialRedirectSubscriber implements EventSubscriberInterface {

  private const TARGET_ROUTE = 'sangel_core.manage_clients_account';

  private AccountProxyInterface $currentUser;

  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 20],
    ];
  }

  /**
   * Redirect commercial users to the gestion dashboard when appropriate.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasRole('commercial')) {
      return;
    }

    $request = $event->getRequest();

    if ($request->isXmlHttpRequest()) {
      return;
    }

    $route_name = $request->attributes->get('_route');
    if ($route_name === self::TARGET_ROUTE) {
      return;
    }

    $path = $request->getPathInfo() ?? '/';

    if ($request->query->has('destination')) {
      return;
    }

    $redirect_routes = [
      'user.login',
      'user.page',
      'system.front_page',
      '<front>',
      'sangel_core.login',
    ];
    $redirect_paths = ['/', '/user', '/user/login', '/login'];

    if (!in_array($route_name, $redirect_routes, TRUE) && !in_array($path, $redirect_paths, TRUE)) {
      return;
    }

    $response = new TrustedRedirectResponse(Url::fromRoute(self::TARGET_ROUTE)->toString());
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $event->setResponse($response);
  }

}
