<?php

namespace Drupal\sangel_core_base\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides placeholder pages rendered via a twig template.
 */
final class ClientPageController extends ControllerBase {

  /**
   * Displays the client cart placeholder.
   */
  public function clientCart(): array {
    return $this->buildPlaceholder('client-cart', $this->t('Panier client'));
  }

  /**
   * Displays the client catalogue placeholder.
   */
  public function clientCatalog(): array {
    return $this->buildPlaceholder('client-catalogue', $this->t('Catalogue client'));
  }

  /**
   * Displays the client order history placeholder.
   */
  public function clientOrderHistory(): array {
    return $this->buildPlaceholder('client-order-history', $this->t('Historique des commandes client'));
  }

  /**
   * Displays the manage clients account placeholder.
   */
  public function manageClientsAccount(): array {
    return $this->buildPlaceholder('manage-clients-account', $this->t('Comptes clients'));
  }

  /**
   * Displays the manage catalogs placeholder.
   */
  public function manageCatalogs(): array {
    return $this->buildPlaceholder('manage-catalogs', $this->t('Les catalogues'));
  }

  /**
   * Displays the manage selections placeholder.
   */
  public function manageSelections(): array {
    return $this->buildPlaceholder('manage-selections', $this->t('Ma sélection'));
  }

  /**
   * Displays the manage history placeholder.
   */
  public function manageHistory(): array {
    return $this->buildPlaceholder('manage-history', $this->t('Mon historique'));
  }

  /**
   * Displays the manage profile placeholder.
   */
  public function manageProfile(): array {
    return $this->buildPlaceholder('manage-profile', $this->t('Gestion du profil'));
  }

  /**
   * Displays the create client management page.
   */
  public function manageClientsCreate(): array {
    return $this->buildGestionPage($this->t('Créer un client'));
  }

  /**
   * Displays the edit client management page.
   */
  public function manageClientsEdit(NodeInterface $node): array {
    $this->assertClientNode($node);
    return $this->buildGestionPage($this->t('Modifier @client', ['@client' => $node->label()]));
  }

  /**
   * Displays the client orders management page.
   */
  public function manageClientsOrders(NodeInterface $node): array {
    $this->assertClientNode($node);
    return $this->buildGestionPage($this->t('Commandes de @client', ['@client' => $node->label()]));
  }

  /**
   * Displays the client cart management page.
   */
  public function manageClientsCartPage(NodeInterface $node): array {
    $this->assertClientNode($node);
    return $this->buildGestionPage($this->t('Panier de @client', ['@client' => $node->label()]));
  }

  /**
   * Displays the client catalogs management page.
   */
  public function manageClientsCatalogsPage(NodeInterface $node): array {
    $this->assertClientNode($node);
    return $this->buildGestionPage($this->t('Catalogues de @client', ['@client' => $node->label()]));
  }

  /**
   * Displays the client profile management page.
   */
  public function manageClientsProfilePage(NodeInterface $node): array {
    $this->assertClientNode($node);
    return $this->buildGestionPage($this->t('Fiche de @client', ['@client' => $node->label()]));
  }

  /**
   * Helper to build a placeholder render array using a twig template.
   */
  protected function buildPlaceholder(string $identifier, string $title): array {
    return [
      '#theme' => 'sangel_core_placeholder_page',
      '#identifier' => $identifier,
      '#title' => $title,
    ];
  }

  /**
   * Helper to build a bare gestion page render array.
   */
  protected function buildGestionPage(string $title): array {
    return [
      '#markup' => '',
      '#title' => $title,
    ];
  }

  /**
   * Ensures the provided node is a client node.
   */
  protected function assertClientNode(NodeInterface $node): void {
    if ($node->bundle() !== 'client') {
      throw new NotFoundHttpException();
    }
  }

}
