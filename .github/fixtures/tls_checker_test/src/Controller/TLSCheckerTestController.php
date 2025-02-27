<?php

namespace Drupal\tls_checker_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tls_checker_test\Service\TLSCheckerTestService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for TLS Test Page.
 */
class TLSCheckerTestController extends ControllerBase {

  /**
   * TLS Test Service.
   *
   * @var \Drupal\tls_checker_test\Service\TLSCheckerTestService
   */
  protected $tlsTestService;

  /**
   * Constructs a TLS test controller.
   */
  public function __construct(TLSCheckerTestService $tlsTestService) {
    $this->tlsTestService = $tlsTestService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tls_checker_test.service')
    );
  }

  /**
   * Test making an outgoing request.
   */
  public function testTLS() {
    $response = $this->tlsTestService->testTLSRequest();
    return [
      '#markup' => '<pre>' . print_r($response, TRUE) . '</pre>',
    ];
  }
}
