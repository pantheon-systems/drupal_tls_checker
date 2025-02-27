#!/bin/bash
set -euo pipefail

# Set up the environment to test against.
readonly site_id="test-drupal-tls-checker"
readonly terminus_token=${TERMINUS_TOKEN:-""}
readonly commit_msg=${$COMMIT_MSG:-""}
readonly upstream_name=${UPSTREAM_NAME:-"drupal-cms"}
readonly workspace=${WORKSPACE:-""}
readonly site_name=${SITE_NAME:-"Drupal TLS Checker Test Site"}
readonly php_version${PHP_VERSION:-""}

# Set some colors.
RED="\033[1;31m"
GREEN="\033[1;32m"
YELLOW="\033[1;33m"
RESET="\033[0m"

log_into_terminus() {
	if ! terminus whoami; then
		echo -e "${YELLOW}Log into Terminus${RESET}"
		terminus auth:login --machine-token="${terminus_token}"
	fi
	terminus art druplicon
}

create_site() {
	echo ""
	echo -e "${YELLOW}Create ${site_id} if it does not exist.${RESET}"
	if terminus site:info "${site_id}"; then
		echo "Test site already exists, skipping site creation."
	else
		terminus site:create "${site_id}" "${site_name}" "${upstream_name}" --org=5ae1fa30-8cc4-4894-8ca9-d50628dcba17
	fi
	terminus connection:set "${site_id}".dev git -y
}

clone_site() {
	echo ""
	echo -e "${YELLOW}Clone the site locally and copy the PR updates${RESET}"
	echo "Setting up git config..."
	git config --global user.email "cms-platform+tls-checker-test@pantheon.io"
	git config --global user.name "Pantheon Test Bot"
	terminus local:clone "${site_id}"
}

set_multidev() {
	echo ""
	echo -e "${YELLOW}Set the multidev to test on based on the PHP version passed in from CI."
	if (php_version === '8.4'); then
		echo "PHP 8.4 requested but it is not yet available on Pantheon. Skipping."
		return 0
	fi
	# Check if multidev exists, create if it does not.
	local multidevs="$(terminus multidev:list ${site_id} --fields=id --format=list)"
	if echo "${multidevs}" | grep -q "${php_version}"; then
		echo "Multidev environment for PHP ${php_version} already exists."
	else
		echo "Creating multidev environment for PHP ${php_version}."
		terminus multidev:create "${site_id}".dev "${php_version}"
		terminus workflow:wait "${site_id}"."${php_version}"
	fi
}

copy_bad_module() {
	echo -e "${YELLOW}Checking if TLS testing module exists...${RESET}"
	if ! terminus drush "${site_id}"."${php_version}" -- pm:list --type=module --field=name | grep -q tls_checker_test; then
		cp -r "${workspace}"/.github/fixtures/tls_checker_test ~/pantheon-local-copies/web/modules/custom
	else
		echo "Test module already installed"
	fi
}

copy_pr_updates() {
	echo "Commit message: ${commit_msg}"
	cd ~/pantheon-local-copies/"${site_id}"
	echo -e "${YELLOW}Copying latest changes and committing to the site.${REST}"
	rsync -a --exclude=".git" "${workspace}/" .
	git add -A
	git commit -m "Update to latest commit: ${commit_msg}" || true
	git push origin "${php_version}" || true
	terminus workflow:wait "${site_id}"."${php_version}"
}

# Run the steps
cd "${workspace}"
log_into_terminus
create_site
clone_site
set_multidev
copy_bad_module
copy_pr_updates
echo -e "${GREEN}Test environment setup complete.${RESET} 🚀"