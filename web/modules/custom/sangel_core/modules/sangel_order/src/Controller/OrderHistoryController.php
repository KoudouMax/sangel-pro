<?php

namespace Drupal\sangel_order\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides order history pages.
 */
class OrderHistoryController extends ControllerBase {

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Displays orders belonging to the given user.
   */
  public function userOrders(UserInterface $user): array {
    $build = [
      '#title' => $this->t('Mes commandes'),
    ];

    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'order')
      ->condition('field_owner', $user->id())
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (!$ids) {
      $build['empty'] = [
        '#markup' => $this->t('Vous n’avez pas encore passé de commande.'),
      ];
      return $build;
    }

    /** @var \Drupal\node\NodeInterface[] $orders */
    $orders = $storage->loadMultiple($ids);

    $rows = array_map(function (NodeInterface $order) {
      $state = $order->get('field_state')->value ?? 'submitted';
      $total = $order->get('field_total')->value ?? '0.00';

      return [
        'data' => [
          Link::fromTextAndUrl($order->label(), $order->toUrl()),
          $this->dateFormatter->format($order->getCreatedTime(), 'medium'),
          ucfirst($state),
          number_format((float) $total, 2, ',', ' ') . ' F CFA',
        ],
      ];
    }, $orders);

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Commande'),
        $this->t('Date'),
        $this->t('Statut'),
        $this->t('Total'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['sangel-order-history']],
      '#empty' => $this->t('Aucune commande enregistrée.'),
    ];

    return $build;
  }

  /**
   * Access check for the orders listing.
   */
  public function accessUserOrders(?UserInterface $user, AccountInterface $account): AccessResult {
    if (!$user) {
      $user = $this->entityTypeManager()->getStorage('user')->load($account->id());
      if (!$user instanceof UserInterface) {
        return AccessResult::forbidden()->cachePerUser();
      }
    }

    if ($account->id() === $user->id()) {
      return AccessResult::allowed()->cachePerUser();
    }

    if ($account->hasPermission('view all sangel orders') || $account->hasPermission('administer sangel orders')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerUser()->cachePerPermissions();
  }

}
