# Drupal TLS Compatibility Checker

[![Unofficial Support](https://img.shields.io/badge/Pantheon-Unofficial_Support-yellow?logo=pantheon&color=FFDC28)](https://docs.pantheon.io/oss-support-levels#unofficial-support)
[![Lint](https://github.com/jazzsequence/drupal_tls_checker/actions/workflows/lint.yml/badge.svg)](https://github.com/jazzsequence/drupal_tls_checker/actions/workflows/lint.yml)
![GitHub Release](https://img.shields.io/github/v/release/jazzsequence/drupal_tls_checker)
![GitHub License](https://img.shields.io/github/license/jazzsequence/drupal_tls_checker)

A scanner for outgoing HTTP requests in Drupal code to check TLS 1.2/1.3 compatibility.

## Installation

### Via Composer

```bash
composer require jazzsequence/drupal_tls_checker
```

### Manual Installation

1. Download the module from the [GitHub repository](https://github.com/jazzsequence/drupal_tls_checker).
2. Extract the downloaded archive.
3. Upload the extracted folder to the `modules/custom` directory of your Drupal installation.
4. Navigate to the **Extend** page in your Drupal admin panel (`/admin/modules`).
5. Find the **TLS Compatibility Checker** module in the list and enable it.
6. Click the **Install** button.

## Usage

There are two ways to use the TLS Checker: via the Drupal admin or via Drush. The module adds a TLS Compatibility Checker page to `/admin/config/development/tls-checker`. This page allows you to run a TLS scan on your site against `/modules` and `/themes` and all subdirectories (including `/contrib` and `/custom`). When the scan is complete, a list of URLs that are not compatible with TLS 1.2 or higher will be displayed.

<!-- add screenshot here -->

You can also run the scan using the [Drush command described below](#drush-commands). 

In either case, both _passing_ and _failing_ urls are stored to the database. Subsequent scans will automatically _skip_ the TLS check for URLs that are known to have passed previously (while still testing URLs that were previously failing). This data can be reset at any time either by using the `tls-checker:reset` command from Drush or in the admin with the "Reset TLS Scan Data" button.

After a scan has been run, if there are any URLs detected that fail the TLS 1.2/1.3 check, an alert will be displayed on the admin page with a list of the failing URLs.