#!/usr/bin/env bats
site_id="${SITE_ID:-"cxr-drupal-cms"}"
php_version="${PHP_VERSION:-"tls"}"

@test "Run TLS checker on all default folders" {
  run terminus drush "${site_id}"."${php_version}" -- tls-checker:scan
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  [ "$status" -eq 0 ]
  [[ "$output" == *"tls-v1-1.badssl.com:1011"* ]]
}

@test "Run TLS data reset" {
  run terminus drush "${site_id}"."${php_version}" -- tls-checker:reset
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  [ "$status" -eq 0 ]  
  [[ "$output" == *"TLS scan data reset."* ]]
}

@test "Run TLS checker report on empty data" {
  run terminus drush "${site_id}"."${php_version}" -- tls-checker:report
  [ "$status" -eq 0 ]  
  [[ "$output" == *"No scan data found."* ]]  
}

@test "Run TLS checker on just custom modules (specified directory)" {
  run terminus drush "${site_id}"."${php_version}" -- tls-checker:scan --directory=modules/custom
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  [ "$status" -eq 0 ]
  [[ "$output" == *"tls-v1-1.badssl.com:1011"* ]]    
}

@test "Run TLS checker report" {
  run terminus drush "${site_id}"."${php_version}" -- tls-checker:report --format=json
    echo "Output: $output"
    echo "Site ID: ${site_id}"
    echo "PHP version: ${php_version}"
    echo "Status: $status"
  [ "$status" -eq 0 ]
  [[ "$output" == *"\"URL\": \"tls-v1-1.badssl.com:1011\""* ]]
}

@test "Check if tls_checker_results table exists" {
  run terminus drush "${site_id}"."${php_version}" -- sqlq "SHOW TABLES LIKE 'tls_checker_results';"
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  [ "$status" -eq 0 ]
  [[ "$output" == *"tls_checker_results"* ]]
}

@test "Check if tls_checker_results table contains failing URL" {
  run terminus drush "${site_id}"."${php_version}" -- sqlq "SELECT * FROM tls_checker_results WHERE url = 'tls-v1-1.badssl.com:1011' AND status = 'failing';"
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  [ "$status" -eq 0 ]
  [[ "$output" == *"tls-v1-1.badssl.com:1011"* ]]
}

@test "Reset the data and confirm the tls_checker_results table does not exist" {
  terminus drush "${site_id}"."${php_version}" -- tls-checker:reset
  echo "Output: $output"
  echo "Site ID: ${site_id}"
  echo "PHP version: ${php_version}"
  echo "Status: $status"
  run terminus drush "${site_id}"."${php_version}" -- sqlq "SELECT * FROM tls_checker_results WHERE url = 'tls-v1-1.badssl.com:1011' AND status = 'failing';"
  [ "$status" -eq 1 ]
}