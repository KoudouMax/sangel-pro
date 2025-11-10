<?php

declare(strict_types=1);

namespace Drupal\sangel_core\Service;

use Drupal\Component\Serialization\Json;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Executes view exports with standardized argument handling.
 */
class CatalogExportManager {

  /**
   * Constructs the manager.
   */
  public function __construct(protected readonly RequestStack $requestStack) {}

  /**
   * Executes the requested view/display and returns the executable.
   *
   * @param string $view_id
   *   View identifier.
   * @param string $display_id
   *   Display identifier.
   * @param array $default_arguments
   *   Optional default arguments when none are provided in the request.
   *
   * @return \Drupal\views\ViewExecutable
   *   Prepared and executed view object.
   */
  public function execute(string $view_id, string $display_id, array $default_arguments = []): ViewExecutable {
    $view = Views::getView($view_id);
    if (!$view || !$view->access($display_id)) {
      throw new AccessDeniedHttpException();
    }

    $view->setDisplay($display_id);

    $request = $this->requestStack->getCurrentRequest();
    $arguments = $default_arguments;

    if ($request instanceof Request) {
      $view->setExposedInput($request->query->all());
      $resolved = $this->resolveArgumentsFromRequest($request);
      if ($resolved !== NULL) {
        $arguments = $resolved;
      }
    }

    if ($arguments) {
      $normalized = [];
      foreach ($arguments as $key => $value) {
        $index = is_numeric($key) ? (int) $key : count($normalized);
        $normalized[$index] = is_array($value) ? reset($value) : $value;
      }
      ksort($normalized);
      $view->setArguments(array_values($normalized));
    }

    $view->preExecute();
    $view->execute();
    return $view;
  }

  /**
   * Resolves arguments passed in the current HTTP request.
   */
  protected function resolveArgumentsFromRequest(Request $request): ?array {
    $arguments_json = $request->query->get('arguments_json');
    if (is_string($arguments_json) && $arguments_json !== '') {
      $decoded = Json::decode($arguments_json);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    $arguments = $request->query->get('arguments');
    if ($arguments !== NULL) {
      if (!is_array($arguments)) {
        $arguments = [$arguments];
      }
      return $arguments;
    }

    return NULL;
  }

}

