<?php

namespace Drupal\tls_checker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\tls_checker\Service\TLSCheckerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AJAX Controller for Processing TLS Scan in Batches.
 */
class ScanController extends ControllerBase {

  /**
   * TLS Checker Service.
   *
   * @var \Drupal\tls_checker\Service\TLSCheckerService
   */
  protected $tlsCheckerService;

  /**
   * Constructs the controller.
   */
  public function __construct(TLSCheckerService $tlsCheckerService) {
    $this->tlsCheckerService = $tlsCheckerService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tls_checker.service')
    );
  }

  /**
   * AJAX callback to process scan in batches.
   */
  public function processBatch(Request $request) {
    $offset = $request->request->get('offset', 0);
    $batch_size = $request->request->get('batch_size', 10);

    $result = $this->tlsCheckerService->scanAll($offset, $batch_size);

    return new JsonResponse($result);
  }

  /**
   * AJAX callback to reset scan data.
   */
  public function resetScan() {
    try {
      \Drupal::logger('tls_checker')->notice('Reset scan request received.');
      $this->tlsCheckerService->resetScanData();
      return new JsonResponse(['success' => TRUE, 'message' => 'TLS scan data has been reset.']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['success' => FALSE, 'message' => $e->getMessage()], 500);
    }
  }

}
