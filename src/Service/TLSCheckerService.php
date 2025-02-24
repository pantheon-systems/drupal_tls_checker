<?php

namespace Drupal\tls_checker\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * TLS Checker Service.
 */
class TLSCheckerService {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * HTTP Client for making requests.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, Client $http_client) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('tls_checker');
    $this->httpClient = $http_client;
  }

	/**
	 * Runs the TLS scan, storing results in the database.
	 */
	public function scanAndStoreUrls(array $urls) {
		$this->ensureResultsTableExists();

		// Remove any URLs that are in the passing urls list.
		$passing_urls = $this->getPassingUrls();
		$failing_urls = $this->getFailingUrls();
		$urls = array_diff($urls, $passing_urls);

		$results = $this->scanUrls($urls);
		$connection = \Drupal::database();
		$processed_urls = [];
	
		try {
			\Drupal::logger('tls_checker')->debug('Scan results: @results', ['@results' => json_encode($results)]);
	
			foreach (['passing', 'failing'] as $status) {
				if (!isset($results[$status]) || !is_array($results[$status])) {
					\Drupal::logger('tls_checker')->warning('Unexpected data type in scan results: @status', ['@status' => $status]);
					continue;
				}

				foreach ($results[$status] as $url) {
					if (!is_string($url)) {
						\Drupal::logger('tls_checker')->warning('Unexpected data type in URLs: @url', ['@url' => json_encode($url)]);
						continue;
					}

					$clean_url = $this->normalizeUrl($url);

					if (!$clean_url) {
						\Drupal::logger('tls_checker')->warning('Invalid URL after normalization: @url', ['@url' => $url]);
						continue;
					}
	
					// Avoid duplicates.
					if (in_array($clean_url, array_unique(array_merge($processed_urls, $passing_urls, $failing_urls)), true) ) {
						continue;
					}

					$processed_urls[] = $clean_url;
					\Drupal::logger('tls_checker')->debug(
						'Storing @status URL: @url', 
						[
							'@status' => ucfirst($status), 
							'@url' => $clean_url,
						]
					);

					// Filter out any duplicates.
					$processed_urls = array_unique($processed_urls);

					$connection->upsert('tls_checker_results')
						->key('url')
						->fields([
							'url' => $clean_url,
							'status' => $status,
						])
						->execute();
				}
			}
	
			return [
				'processed' => count($processed_urls),
				'passing' => count($results['passing']),
				'failing' => count($results['failing']),
				'failing_urls' => $results['failing'],
			];
		} catch (\Exception $e) {
			\Drupal::logger('tls_checker')->error('Error storing TLS scan results: ' . $e->getMessage());
			return ['error' => 'Failed to store scan results.'];
		}
	}	

	private function normalizeUrl(string $url) {
		$parsed_url = parse_url($url);

		if (!isset($parsed_url['host'])) {
			\Drupal::logger('tls_checker')->warning('Invalid url: @url', ['@url' => $url]);
			return null; // Invalid url.
		}

		$hostname = strtolower($parsed_url['host']);

		if (!$hostname) {
			\Drupal::logger('tls_checker')->warning('Invalid hostname extracted: @url', ['@url' => $url]);
			return null;
		}

		$hostname = preg_replace('/^www\./', '', $hostname); // Remove 'www.'

		// Keep non-standard ports (if present)
		if (isset($parsed_url['port']) && !in_array($parsed_url['port'], [80, 443])) {
			return "$hostname:{$parsed_url['port']}";
		}
	
		return $hostname; // Default case, return clean hostname
	}

	/**
	 * Extracts URLs from PHP files in modules and themes.
	 *
	 * @return array List of unique URLs found in the codebase.
	 */
	public function extractUrlsFromCodebase(array $base_dirs = ['modules', 'themes']) {
		$urls = [];

		foreach ($base_dirs as $dir) {
			$full_path = DRUPAL_ROOT . '/' . $dir;
			if (!is_dir($full_path)) {
				continue;
			}

			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full_path));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}

				$code = file_get_contents($file->getRealPath());
				$tokens = token_get_all($code);

				foreach ($tokens as $token) {
					if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
						$string = trim($token[1], "'\""); // Remove surrounding quotes

						// Parse the URL.
						$parsed_url = parse_url($string);
						if (empty($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) {
							continue; // Skip non-http URLs.
						}

						if (empty($parsed_url['host'])) {
							continue;
						}

						$hostname = strtolower($parsed_url['host']); // Normalize case.
						$port = isset($parsed_url['port']) ? ":{$parsed_url['port']}" : '';

						// Exclude localhost, IPs and invalid domains.
						if ( $hostname === 'localhost' || filter_var($hostname, FILTER_VALIDATE_IP) ) {
							continue;
						}

						// Ensure the hostname has a valid(ish) TLD.
						if (!preg_match('/\.[a-z]{2,}$/i', $hostname)) {
							continue;
						}

						// Skip URLs with curly braces or square brackets (likely placeholders)
						if (preg_match('/[\{\}\[\]]/', $hostname)) {
							continue;
						}						

						$urls[] = "{$parsed_url['scheme']}://{$hostname}{$port}";
					}
				}
			}
		}

		return array_values(array_unique($urls));
	}
  
	public function getScanResults() {
		$passing = $this->getPassingUrls();
		$failing = $this->getFailingUrls();

		return [
			'passing' => count($passing),
			'failing' => count($failing),
			'failing_urls' => $failing,
		];
	}

	/**
	 * Checks if a hostname supports TLS 1.2 or 1.3.
	 *
	 * @param string $hostname
	 *   The hostname to check.
	 * @param int $port
	 *   The port number to check (default 443).
	 *
	 * @return bool
	 *   TRUE if TLS 1.2 or 1.3 is supported, FALSE otherwise.
	 */
	protected function checkTlsSupport(string $hostname, int $port = 443) {
		$crypto_methods = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
		if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
			$crypto_methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
		}

		$context = stream_context_create([
			'ssl' => [
				'crypto_method' => $crypto_methods,
				'verify_peer' => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
				'capture_peer_cert' => true,  // Capture the cert for debugging
			],
		]);

		try {
			$fp = @stream_socket_client("ssl://$hostname:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

			if ($fp) {
				fclose($fp);
				return true;
			} else {
				\Drupal::logger('tls_checker')->debug("TLS check failed for @hostname. Error Code: @errno, Message: @message", [
					'@hostname' => $hostname,
					'@errno' => $errno,
					'@message' => $errstr ?: "Unknown error",
				]);
				return false;
			}
		} catch (\Exception $e) {
			\Drupal::logger('tls_checker')->error("Exception during TLS check for @hostname: @message", [
				'@hostname' => $hostname,
				'@message' => $e->getMessage(),
			]);
			return false;
		}
	}
  
	/**
	 * Resets stored TLS scan results.
	 */
	public function resetScanData() {
		$connection = \Drupal::database();
		$schema = $connection->schema();
	
		if ($schema->tableExists('tls_checker_results')) {
			// Explicitly drop the table using a raw query
			$connection->query("DROP TABLE IF EXISTS {tls_checker_results}");
	
			// Verify deletion
			if ($schema->tableExists('tls_checker_results')) {
				\Drupal::logger('tls_checker')->error('Failed to drop the TLS scan results table.');
			} else {
				\Drupal::logger('tls_checker')->debug('TLS scan data has been fully reset.');
			}
		} else {
			\Drupal::logger('tls_checker')->warning('Attempted to reset scan data, but the results table does not exist.');
		}
	}	

	/**
	 * Ensures the database table for storing scan results exists.
	 */
	private function ensureResultsTableExists() {
		$connection = \Drupal::database();
		$schema = $connection->schema();

		if (!$schema->tableExists('tls_checker_results')) {
			$schema->createTable('tls_checker_results', [
				'fields' => [
					'id' => [
						'type' => 'serial',
						'not null' => TRUE,
					],
					'url' => [
						'type' => 'varchar',
						'length' => 255,
						'not null' => TRUE,
					],
					'status' => [
						'type' => 'varchar',
						'length' => 10,
						'not null' => TRUE,
					],
				],
				'primary key' => ['id'],
			]);
			\Drupal::logger('tls_checker')->debug('Created missing tls_checker_results table.');
		}
	}

	/**
	 * Retrieves passing URLs.
	 *
	 * @return array
	 *   List of passing URLs.
	 */
	public function getPassingUrls() {
		$query = \Drupal::database()->select('tls_checker_results', 't')
			->fields('t', ['url'])
			->condition('status', 'passing')
			->execute();

		$passing_urls = [];
		foreach ($query as $record) {
			$passing_urls[] = $record->url;
		}

		return $passing_urls;
	}

	/**
	 * Retrieves failing URLs.
	 *
	 * @return array
	 *   List of failing URLs.
	 */
	public function getFailingUrls() {
		$query = \Drupal::database()->select('tls_checker_results', 't')
			->fields('t', ['url'])
			->condition('status', 'failing')
			->execute();

		$failing_urls = [];
		foreach ($query as $record) {
			$failing_urls[] = $record->url;
		}

		return $failing_urls;		
	}

	/**
	 * Runs TLS checks on a list of URLs and categorizes them as passing or failing.
	 *
	 * @param array $urls
	 *   List of URLs to scan.
	 *
	 * @return array
	 *   An associative array with 'passing' and 'failing' URLs.
	 */
	private function scanUrls(array $urls) {
		$results = [
			'passing' => [],
			'failing' => [],
		];

		$hostnames = [];

		// Extract hostnames from provided URLs
		foreach ($urls as $url) {
			$parsedUrl = parse_url($url);

			if (!isset($parsedUrl['host'])) {
				\Drupal::logger('tls_checker')->warning('Invalid URL skipped: @url', ['@url' => $url]);
				continue;
			}

			$hostname = $parsedUrl['host'];
			$port = $parsedUrl['port'] ?? 443; // Default to TLS port 443 if none specified

			$hostKey = "{$hostname}:{$port}"; // Preserve host:port uniqueness
			$hostnames[$hostKey] = ['hostname' => $hostname, 'port' => $port];
		}

		// Scan each unique hostname
		foreach ($hostnames as $hostKey => $data) {
			$hostname = $data['hostname'];
			$port = $data['port'];

			if ($this->checkTlsSupport($hostname, $port)) {
				$results['passing'][] = $hostKey;
			} else {
				$results['failing'][] = $hostKey;
			}
		}

		return $results;
	}
}
