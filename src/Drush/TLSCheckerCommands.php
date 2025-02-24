<?php

namespace Drupal\tls_checker\Drush;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\tls_checker\Service\TLSCheckerService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Drush commands for TLS Checker.
 */
final class TLSCheckerCommands extends DrushCommands {

	/**
	 * TLS Checker service.
	 *
	 * @var \Drupal\tls_checker\Service\TLSCheckerService
	 */
	protected $tlsCheckerService;

	/**
	 * Constructs the TLSCheckerCommands object.
	 */
	public function __construct(?TLSCheckerService $tlsCheckerService = null) {
		parent::__construct();
		$this->tlsCheckerService = $tlsCheckerService ?? \Drupal::service('tls_checker.service');
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
		$this->output()->writeln("🔍 Collecting URLs from codebase...");

		// Extract URLs.
		$urls = $this->tlsCheckerService->extractUrlsFromCodebase();

		if (empty($urls)) {
			$this->output()->writeln("⚠️ No URLs found in codebase.");
			return;
		}

		$this->output()->writeln("✅ Found " . count($urls) . " URLs in codebase.");
		$this->output()->writeln("📊 Starting TLS Scan...");

		// Set up progress bar.
		$progressBar = new ProgressBar($this->output(), count($urls));
		$progressBar->start();

		// Process URLs in batches.
		$batchSize = 10;
		$totalUrls = count($urls);
		$processedUrls = 0;
		$passing = 0;
		$failing = 0;
		$failingUrls = [];

		while ($processedUrls < $totalUrls) {
			$batch = array_slice($urls, $processedUrls, $batchSize);

			try {
				$this->tlsCheckerService->scanAndStoreUrls($batch);
			} catch (\Exception $e) {
				\Drupal::logger('tls_checker')->warning('Batch error: ' . $e->getMessage());
			}
			
			$processedUrls += count($batch);
			$progressBar->advance(count($batch));
		}

		$progressBar->finish();
		$this->output()->writeln("\n🏁 Scan complete!");

		// Get final results from the database via the batch controller.
		$finalResults = $this->tlsCheckerService->getScanResults();

		$passing = $finalResults['passing'] ?? 0;
		$failing = $finalResults['failing'] ?? 0;
		$failingUrls = $finalResults['failing_urls'] ?? [];

		// Output summary.
		$this->output()->writeln("✅ Passing Domains $passing");
		$this->output()->writeln("❌ Failing Domains: $failing");

		if (!empty($failingUrls)) {
			$this->output()->writeln("⚠️ The following domains are not compatible with TLS 1.2 or 1.3:");
			foreach ($failingUrls as $url) {
				$this->output()->writeln("- $url");
			}
		}
	}

	/**
	 * Resets the TLS scan <data value="
	 * 
	 * @command tls-checker:reset
	 * @aliases tls-reset
	 * @usage drush tls-checker:reset
	 *  Resets the TLS scan results.
	 */
	#[CLI\Command(name: 'tls-checker:reset', aliases: ['tls-reset'])]
	public function reset() {
		$this->output()->writeln("🧹 Resetting TLS scan data...");

		try {
			$this->tlsCheckerService->resetScanData();
			$this->output()->writeln("✅ TLS scan data reset.");
		} catch (\Exception $e) {
			$this->output()->writeln("❌ An error occurred resetting scan data: " . $e->getMessage());
		}
	}
}
