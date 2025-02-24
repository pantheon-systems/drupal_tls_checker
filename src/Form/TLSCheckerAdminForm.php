<?php

namespace Drupal\tls_checker\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tls_checker\Service\TLSCheckerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\InvokeCommand;

/**
 * TLS Checker Admin Form.
 */
class TLSCheckerAdminForm extends FormBase {

  /**
   * TLS Checker service.
   *
   * @var \Drupal\tls_checker\Service\TLSCheckerService
   */
  protected $tlsCheckerService;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs the form.
   */
  public function __construct(TLSCheckerService $tlsCheckerService, AccountProxyInterface $current_user, MessengerInterface $messenger) {
    $this->tlsCheckerService = $tlsCheckerService;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tls_checker.service'),
      $container->get('current_user'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tls_checker_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if user has permission to run scans.
    if (!$this->currentUser->hasPermission('run tls checker scan')) {
      return [
        '#markup' => $this->t('You do not have permission to run TLS scans.'),
      ];
    }

    $form['#attached']['library'][] = 'tls_checker/tls_checker';
    $form['description'] = [
      '#markup' => $this->t('<div class="tls-checker-description">Scan your site’s modules and themes for outgoing HTTP requests and check TLS 1.2/1.3 compatibility.</div>'),
    ];

    // Get information from the database.
    $connection = \Drupal::database();
    $schema = $connection->schema();
    $table_exists = FALSE;
    if ($schema->tableExists('tls_checker_results')) {
      $query = $connection->select('tls_checker_results', 'tcr')
        ->fields('tcr', ['url'])
        ->condition('status', 'failing')
        ->execute();
      $table_exists = TRUE;
    }
    $failing_urls = $table_exists ? $query->fetchCol() : [];

    // Render failing results.
    if (!empty($failing_urls)) {
      $form['failing_urls'] = [
        '#type' => 'details',
        '#title' => $this->t('Failing URLs'),
        '#open' => TRUE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => array_map('htmlspecialchars', $failing_urls),
        ],
      ];

      $form['description'] = [
        '#markup' => $this->t('The following URLs failed TLS 1.2/1.3 compatibility. Use the TLS Scan button below to re-run the scan or the Reset Scan Data button to clear the results.'),
      ];
    }

    $form['progress'] = [
      '#type' => 'markup',
      '#markup' => '<div id="tls-scan-progress" class="tls-progress-bar">
						<div class="tls-progress"></div>
						<span id="tls-progress-text" style="display:inline!important">0%</span>
					  </div>',
    ];

    $form['scan_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Run TLS Scan'),
      '#attributes' => ['id' => 'tls-start-scan'],
      '#ajax' => [
        'callback' => '::startScan',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Scanning...'),
        ],
      ],
    ];

    $form['reset_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Reset TLS Scan Data'),
      '#attributes' => ['id' => 'tls-reset-scan'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $results = $this->tlsCheckerService->scanAll();

      // Ensure the response structure is always an array.
      if (!is_array($results)) {
        $results = ['passing' => [], 'failing' => []];
      }

      // Ensure 'passing' and 'failing' are arrays before calling count().
      $passingUrls = isset($results['passing']) && is_array($results['passing']) ? $results['passing'] : [];
      $failingUrls = isset($results['failing']) && is_array($results['failing']) ? $results['failing'] : [];

      $message = $this->t('The TLS scan has been completed. <br><strong>Passing URLs:</strong> @passing <br><strong>Failing URLs:</strong> @failing', [
        '@passing' => count($passingUrls),
        '@failing' => count($failingUrls),
      ]);

      // Show detailed failing URLs if any.
      if (!empty($failingUrls)) {
        $message .= '<br><strong>Failing URLs:</strong><br>' . implode('<br>', array_map('htmlspecialchars', $failingUrls));
      }

      $this->messenger->addMessage($message, 'status', TRUE);
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred during the TLS scan: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * AJAX callback for running the scan in batches.
   */
  public function startScan(array &$form, FormStateInterface $form_state) {
    \Drupal::logger('tls_checker')->info('🚀 AJAX Scan triggered.');
    $response = new AjaxResponse();

    // Get all previously scanned domains.
    $connection = \Drupal::database();
    $existing_scan_results = $connection->query('SELECT domain, status FROM {tls_checker_results}')->fetchAllAssoc('domain');

    // Extract new domains to scan.
    $all_domains = $this->tlsCheckerService->extractUrlsFromCodebase();
    $domains_to_scan = [];

    foreach ($all_domains as $domain) {
      if (!isset($existing_scan_results[$domain]) || $existing_scan_results[$domain]->status === 'failing') {
        $domains_to_scan[] = $domain;
      }
    }

    $total_domains = count($domains_to_scan);

    if ($total_domains === 0) {
      $response->addCommand(new MessageCommand($this->t('No new domains to scan.'), NULL, ['type' => 'warning']));
      return $response;
    }

    // Store in DB for batch processing.
    foreach ($domains_to_scan as $domain) {
      $connection->merge('tls_checker_results')
        ->key('domain', $domain)
        ->fields(['status' => 'pending'])
        ->execute();
    }

    // Show the progress bar and reset to 0%.
    $response->addCommand(new InvokeCommand('#tls-scan-progress', 'show'));
    $response->addCommand(new InvokeCommand('.tls-progress', 'css', ['width', '0%']));
    $response->addCommand(new InvokeCommand('#tls-progress-text', 'text', ['0%']));

    // Start batch processing.
    $response->addCommand(new InvokeCommand('body', 'tlsCheckerProcessBatch', []));

    return $response;
  }

}
