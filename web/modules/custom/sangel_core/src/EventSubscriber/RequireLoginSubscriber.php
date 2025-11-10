<?php

namespace Drupal\sangel_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Force l'authentification sur tout le site (sauf routes whitelistes).
 */
class RequireLoginSubscriber implements EventSubscriberInterface {

  protected AccountProxyInterface $currentUser;

  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 35],
    ];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // Déjà connecté ? OK.
    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    $request = $event->getRequest();
    $route_name = $request->attributes->get('_route') ?? '';
    $path = $request->getPathInfo() ?: '/';

    // --- Whitelists ---
    $route_allowlist = [
      'sangel_core.login',
      'user.login',
      'user.logout',
      'user.pass',
      'system.403',
      'system.404',
    ];
    $path_allowlist_exact = [
      '/login',
      '/user/login',
      '/user/password',
    ];
    $path_allowlist_regex = [
      '#^/core/#',
      '#^/modules/#',
      '#^/themes/#',
      '#^/libraries/#',
      '#^/sites/default/files/#',
      '#^/favicon\\.ico$#',
      '#^/robots\\.txt$#',
    ];

    // Laisser passer si route/chemin whitelists.
    if ($route_name && in_array($route_name, $route_allowlist, true)) {
      return;
    }
    if (in_array($path, $path_allowlist_exact, true)) {
      return;
    }
    foreach ($path_allowlist_regex as $re) {
      if (preg_match($re, $path)) {
        return;
      }
    }
    // Anti-boucle simple.
    if ($path === '/login' || $path === '/user/login') {
      return;
    }

    // --- Choisir une route de login valide (fallback fiable) ---
    $login_route = 'user.login';
    try {
      \Drupal::service('router.route_provider')->getRouteByName('sangel_catalogue.login');
      $login_route = 'sangel_catalogue.login';
    } catch (RouteNotFoundException $e) {
      // ignore → on garde user.login
    }

    // Destination : seulement si ce n’est pas déjà une page whitelistée.
    $options = ['absolute' => FALSE];
    if (!in_array($path, $path_allowlist_exact, true)) {
      $options['query']['destination'] = $path;
    }

    $login_url = Url::fromRoute($login_route, [], $options)->toString();

    $response = new TrustedRedirectResponse($login_url, 302);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    $event->setResponse($response);
  }
}
