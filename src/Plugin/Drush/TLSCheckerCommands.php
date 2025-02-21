<?php

namespace Drupal\tls_checker\Plugin\Drush;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\tls_checker\Service\TLSCheckerService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Drush commands for TLS Checker.
 */
final class TLSCheckerCommands extends DrushCommands {

  /**
   * The TLS Checker service.
   *
   * @var \Drupal\tls_checker\Service\TLSCheckerService
   */
  protected $tlsCheckerService;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs the TLSCheckerCommands object.
   */
  public function __construct(TLSCheckerService $tlsCheckerService, LoggerChannelFactoryInterface $loggerFactory) {
    $this->tlsCheckerService = $tlsCheckerService;
    $this->logger = $loggerFactory->get('tls_checker');
  }

  /**
   * Run a TLS scan via Drush.
   *
   * @command tls-checker:scan
   * @aliases tls-scan
   * @usage drush tls-checker:scan
   *   Runs a TLS 1.2/1.3 compatibility scan.
   */
  #[CLI\Command(name: 'tls-checker:scan', aliases: ['tls-scan'])]
  public function scan() {
    $this->logger->info('Starting TLS scan...');
    $this->output()->writeln("Scanning for outgoing HTTP requests and checking TLS compatibility...");

    $results = $this->tlsCheckerService->scanAll();

    $failing_urls = $results['failing'] ?? [];
    $passing_count = count($results['passing'] ?? []);
    $failing_count = count($failing_urls);

    // Output results to Drush CLI.
    $this->output()->writeln("Scan complete.");
    $this->output()->writeln("✅ Passing URLs: $passing_count");
    $this->output()->writeln("❌ Failing URLs: $failing_count");

    if (!empty($failing_urls)) {
      $this->output()->writeln("The following URLs failed TLS 1.2/1.3 compatibility:");
      foreach ($failing_urls as $url) {
        $this->output()->writeln("- $url");
      }
    }

    $this->logger->info("TLS scan completed: $passing_count passing, $failing_count failing.");
  }
}
