<?php

namespace Drupal\tls_checker_test\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Service to test TLS requests.
 */
class TLSCheckerTestService {

  /**
   * Performs a request against a known bad TLS URL.
   */
  public function testTLSRequest() {
    $client = new Client([
    // Enforce TLS certificate verification.
      'verify' => TRUE,
      'timeout' => 5,
    ]);

    try {
      $response = $client->get('https://tls-v1-1.badssl.com:1011/');
      return [
        'status' => 'success',
        'code' => $response->getStatusCode(),
        'body' => $response->getBody()->getContents(),
      ];
    }
    catch (RequestException $e) {
      return [
        'status' => 'error',
        'message' => $e->getMessage(),
      ];
    }
  }

}
