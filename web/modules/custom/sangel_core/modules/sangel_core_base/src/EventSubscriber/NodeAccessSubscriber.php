<?php

namespace Drupal\sangel_core_base\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class NodeAccessSubscriber implements EventSubscriberInterface {

  protected AccountProxyInterface $currentUser;

  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 29],
    ];
  }

  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = trim($request->getPathInfo(), '/');

    if (str_starts_with($path, 'node___/')) {
      if ($this->currentUser->isAuthenticated() && ($this->currentUser->hasPermission('administer nodes') || $this->currentUser->hasPermission('access administration pages'))) {
        return;
      }

      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $event->setResponse($response);
    }
  }

}
