<?php

namespace Drupal\sangel_product\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\sangel_product\Service\CatalogSelectionManager;
use Drupal\sangel_core\Ajax\UpdateCounterCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX endpoints for catalog selection interactions.
 */
class CatalogAjaxController extends ControllerBase {

  protected CatalogSelectionManager $selectionManager;
  protected RendererInterface $renderer;

  public function __construct(CatalogSelectionManager $selection_manager, RendererInterface $renderer) {
    $this->selectionManager = $selection_manager;
    $this->renderer = $renderer;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('sangel_product.catalog_selection_manager'),
      $container->get('renderer')
    );
  }

  public function add(NodeInterface $node): AjaxResponse {
    $response = new AjaxResponse();

    if (!$this->currentUser()->isAuthenticated()) {
      $this->messenger()->addWarning($this->t('Veuillez vous connecter pour gérer votre catalogue.'));
      $this->attachMessages($response);
      return $response;
    }

    if ($node->bundle() !== 'product') {
      $this->messenger()->addError($this->t('Ce contenu ne peut pas être ajouté au catalogue.'));
      $this->attachMessages($response);
      return $response;
    }

    try {
      $already = in_array($node->id(), $this->selectionManager->getItems(), TRUE);
      $added = $this->selectionManager->addProduct($node);

      if ($added) {
        $this->messenger()->addStatus($this->t('@title a été ajouté à votre catalogue.', ['@title' => $node->label()]));
      }
      elseif ($already) {
        $this->messenger()->addWarning($this->t('@title est déjà présent dans votre catalogue.', ['@title' => $node->label()]));
      }
      else {
        $this->messenger()->addError($this->t('Impossible d’ajouter @title à votre catalogue pour le moment.', ['@title' => $node->label()]));
      }

      $this->attachMessages($response);

      $total = $this->selectionManager->getItemCount();
      $response->addCommand(new UpdateCounterCommand('#sangel_header_counter__catalog', $total));
    }
    catch (\Throwable $e) {
      $this->getLogger('sangel_product')->error('Add to catalog failed: @m', ['@m' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Une erreur est survenue. Veuillez réessayer.'));
      $this->attachMessages($response);
    }

    return $response;
  }

  public function remove(NodeInterface $node, Request $request): AjaxResponse {
    $response = new AjaxResponse();

    if (!$this->currentUser()->isAuthenticated()) {
      $this->messenger()->addWarning($this->t('Veuillez vous connecter pour gérer votre catalogue.'));
      $this->attachMessages($response);
      return $response;
    }

    if ($node->bundle() !== 'product') {
      $this->messenger()->addError($this->t('Ce contenu ne peut pas être retiré du catalogue.'));
      $this->attachMessages($response);
      return $response;
    }

    try {
      $this->selectionManager->removeProduct((int) $node->id());
      $this->messenger()->addStatus($this->t('@title a été retiré de votre catalogue.', ['@title' => $node->label()]));
      $this->attachMessages($response);

      $total = $this->selectionManager->getItemCount();
      $response->addCommand(new UpdateCounterCommand('#sangel_header_counter__catalog', $total));

      $target = (string) $request->query->get('target', '');
      if ($target !== '') {
        $selector = str_starts_with($target, '#') ? $target : '#' . $target;
        $response->addCommand(new RemoveCommand($selector));
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('sangel_product')->error('Remove from catalog failed: @m', ['@m' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Une erreur est survenue. Veuillez réessayer.'));
      $this->attachMessages($response);
    }

    return $response;
  }

  protected function attachMessages(AjaxResponse $response): void {
    $build = ['#type' => 'status_messages'];
    $messages = $this->renderer->renderRoot($build);
    $response->addCommand(new HtmlCommand('[data-drupal-messages]', $messages));
  }
}
