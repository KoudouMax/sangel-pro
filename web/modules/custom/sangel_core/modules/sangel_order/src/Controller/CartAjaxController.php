<?php

namespace Drupal\sangel_order\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Url;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\sangel_order\Service\CartManager;
use Drupal\sangel_core\Ajax\UpdateCounterCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AJAX endpoints for cart interactions.
 */
class CartAjaxController extends ControllerBase {

  /**
   * Cart manager service.
   */
  protected CartManager $cartManager;

  /**
   * Renderer service.
   */
  protected RendererInterface $renderer;

  /**
   * Block manager service.
   */
  protected BlockManagerInterface $blockManager;

  /**
   * Constructs the controller.
   */
  public function __construct(CartManager $cart_manager, RendererInterface $renderer, BlockManagerInterface $block_manager) {
    $this->cartManager = $cart_manager;
    $this->renderer = $renderer;
    $this->blockManager = $block_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('sangel_order.cart_manager'),
      $container->get('renderer'),
      $container->get('plugin.manager.block')
    );
  }

  /**
   * Adds a product to the cart and returns an AJAX response.
   */
  public function add(NodeInterface $node, Request $request): AjaxResponse {
    $response = new AjaxResponse();

    if ($node->bundle() !== 'product') {
      $this->messenger()->addError($this->t('Ce contenu ne peut pas être ajouté au panier.'));
      $this->attachMessages($response);
      return $response;
    }

    $quantity_requested = max(1, (int) $request->query->get('quantity', 1));
    $new_quantity = $this->cartManager->addProduct($node, $quantity_requested);

    if ($new_quantity > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $quantity_requested,
        '@title a été ajouté à votre panier.',
        '@count exemplaires de @title ont été ajoutés à votre panier.',
        [
          '@title' => $node->label(),
          '@count' => $quantity_requested,
        ]
      ));
    }
    else {
      $this->messenger()->addError($this->t('Impossible d’ajouter @title au panier pour le moment.', ['@title' => $node->label()]));
    }

    $this->attachMessages($response);

    $total = $this->cartManager->getItemCount();
    $response->addCommand(new UpdateCounterCommand('#sangel_header_counter__cart', $total));

    return $response;
  }

  /**
   * Attaches the current status messages to the AJAX response.
   */
  protected function attachMessages(AjaxResponse $response): void {
    $build = [
      '#type' => 'status_messages',
    ];
    $messages = $this->renderer->renderRoot($build);
    $response->addCommand(new HtmlCommand('[data-drupal-messages]', $messages));
  }

  /**
   * Removes a product from the cart via AJAX.
   */
  public function remove(NodeInterface $node, Request $request): AjaxResponse {
    $response = new AjaxResponse();

    if ($node->bundle() !== 'product') {
      $this->messenger()->addError($this->t('Ce contenu ne peut pas être retiré du panier.'));
      $this->attachMessages($response);
      return $response;
    }

    $this->cartManager->removeProduct((int) $node->id());
    $this->messenger()->addStatus($this->t('@title a été retiré du panier.', ['@title' => $node->label()]));
    $this->attachMessages($response);

    $snapshot = \sangel_core_base_build_cart_snapshot();

    $response->addCommand(new UpdateCounterCommand('#sangel_header_counter__cart', $snapshot['count']));
    $response->addCommand(new HtmlCommand('[data-cart-summary-count]', (string) $snapshot['count']));
    $response->addCommand(new HtmlCommand('[data-cart-summary-total]', $snapshot['total_formatted'] . ' F CFA'));
    $response->addCommand(new HtmlCommand('[data-cart-summary-checkout]', $this->renderCartCheckoutAction($snapshot)));

    $target = (string) $request->query->get('target', '');
    if ($target !== '') {
      $selector = str_starts_with($target, '#') ? $target : '#' . $target;
      $response->addCommand(new RemoveCommand($selector));
    }

    if ($snapshot['count'] === 0) {
      $empty_build = [
        '#theme' => 'sangel_core_cart_items',
        '#items' => [],
        '#empty_text' => $this->t('Panier vide'),
      ];
      $markup = $this->renderer->renderRoot($empty_build);
      $response->addCommand(new ReplaceCommand('[data-sangel-cart-items-wrapper]', $markup));
    }

    $response->setAttachments([
      'library' => ['core/drupal.ajax', 'sangelpro_theme/cart-actions'],
    ]);

    return $response;
  }

  /**
   * Updates the quantity for a product in the cart via AJAX.
   */
  public function update(NodeInterface $node, Request $request): AjaxResponse {
    $response = new AjaxResponse();

    if ($node->bundle() !== 'product') {
      return $response;
    }

    $quantity = max(0, (int) $request->query->get('quantity', 0));
    $this->cartManager->setQuantity((int) $node->id(), $quantity);

    $snapshot = \sangel_core_base_build_cart_snapshot();

    $response->addCommand(new UpdateCounterCommand('#sangel_header_counter__cart', $snapshot['count']));
    $response->addCommand(new HtmlCommand('[data-cart-summary-count]', (string) $snapshot['count']));
    $response->addCommand(new HtmlCommand('[data-cart-summary-total]', $snapshot['total_formatted'] . ' F CFA'));
    $response->addCommand(new HtmlCommand('[data-cart-summary-checkout]', $this->renderCartCheckoutAction($snapshot)));

    $replacement_build = [
      '#theme' => 'sangel_core_cart_items',
      '#cart_is_read_only' => FALSE,
    ];
    $markup = $this->renderer->renderRoot($replacement_build);
    $response->addCommand(new ReplaceCommand('[data-sangel-cart-items-wrapper]', $markup));

    $response->setAttachments([
      'library' => ['core/drupal.ajax', 'sangelpro_theme/cart-actions'],
    ]);

    return $response;
  }

  /**
   * Renders the checkout action button markup for the cart sidebar.
   */
  protected function renderCartCheckoutAction(array $snapshot): string {
    if ($snapshot['is_empty']) {
      $build = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => [
            'inline-flex',
            'cursor-not-allowed',
            'items-center',
            'justify-center',
            'rounded-full',
            'bg-sangel-ice',
            'px-6',
            'py-3',
            'text-sm',
            'font-semibold',
            'text-sangel-text/50',
          ],
        ],
        '#value' => $this->t('Valider ma commande'),
      ];
      return $this->renderer->renderRoot($build);
    }

    if ($this->currentUser()->isAuthenticated()) {
      $build = [
        '#type' => 'link',
        '#title' => $this->t('Valider ma commande'),
        '#url' => Url::fromRoute('sangel_order.checkout'),
        '#attributes' => [
          'class' => [
            'inline-flex',
            'items-center',
            'justify-center',
            'rounded-full',
            'bg-sangel-primary',
            'px-6',
            'py-3',
            'text-sm',
            'font-semibold',
            'text-white',
            'shadow',
            'hover:bg-sangel-primary/90',
          ],
        ],
      ];
      return $this->renderer->renderRoot($build);
    }

    $login_url = Url::fromRoute('user.login', [], [
      'query' => ['destination' => '/compte-clients/panier'],
    ]);
    $build = [
      '#type' => 'link',
      '#title' => $this->t('Me connecter pour finaliser'),
      '#url' => $login_url,
      '#attributes' => [
        'class' => [
          'inline-flex',
          'items-center',
          'justify-center',
          'rounded-full',
          'border',
          'border-sangel-primary',
          'px-6',
          'py-3',
          'text-sm',
          'font-semibold',
          'text-sangel-primary',
          'shadow',
          'hover:bg-sangel-primary/10',
        ],
      ],
    ];
    return $this->renderer->renderRoot($build);
  }

}
