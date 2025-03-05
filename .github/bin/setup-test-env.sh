#!/bin/bash
set -euo pipefail

# Set up the environment to test against.
readonly terminus_token=${TERMINUS_TOKEN:-""}
readonly commit_msg=${COMMIT_MSG:-""}
readonly upstream_name=${UPSTREAM_NAME:-"drupal-cms-composer-managed"}
readonly workspace=${WORKSPACE:-""}
readonly site_name=${SITE_NAME:-"Drupal TLS Checker Test Site"}
# shellcheck disable=SC2153
readonly php_version=${PHP_VERSION//./} 
readonly pr_num=${PR_NUMBER:-""}

# Set some colors.
RED="\033[1;31m"
GREEN="\033[1;32m"
YELLOW="\033[1;33m"
RESET="\033[0m"

get_site_id() {
	if [[ $php_version == '83' ]]; then
		echo "test-drupal-cms-tls-checker-83"
	else
		echo "test-drupal-tls-checker-${php_version}"
	fi
}

# shellcheck disable=SC2155
readonly site_id=$(get_site_id)

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

	if terminus plan:info "${site_id}" | grep -q "Sandbox"; then
		echo "Site is on a sandbox plan, setting to performance_small."
		terminus plan:set "${site_id}" "plan-performance_small-contract-annual-1"
	fi

	update_drupal_core
}

clone_site() {
	echo ""
	echo -e "${YELLOW}Clone the site locally${RESET}"
	echo "Setting up git config..."
	git config --global user.email "cms-platform+tls-checker-test@pantheon.io"
	git config --global user.name "Pantheon Test Bot"
	terminus local:clone "${site_id}"
	composer install
	# Create the custom modules directory if it doesn't already exist.
	mkdir -p ~/pantheon-local-copies/"${site_id}"/web/modules/custom
}

update_drupal_core() {
	# Switch to Git mode and clear out any possible changes on the remote.
	terminus connection:set "${site_id}.dev" git -y
    echo -e "${YELLOW}Checking Drupal core version...${RESET}"
    
    # Get the current installed Drupal version
    local current_version
    current_version=$(terminus drush "${site_id}.dev" -- core:status --format=list --fields=drupal-version | cut -d. -f1)

    echo "Current Drupal version: ${current_version}"

    # Check if the version is below 11
    if [[ "$current_version" -lt 10 ]]; then
        # Switch to SFTP mode if necessary
        terminus connection:set "${site_id}.dev" sftp -y

		echo -e "${YELLOW}Updating Drush to 12...${RESET}"
		terminus composer "${site_id}.dev" -- require drush/drush:^12 --update-with-all-dependencies -W

        echo -e "${YELLOW}Updating Drupal core to version 10...${RESET}"
        
        # Run Composer update for Drupal core
		terminus composer "${site_id}.dev" -- remove drupal/core-recommended pantheon-systems/drupal-integrations
		terminus composer "${site_id}.dev" -- require drupal/core-recommended:^10 symfony/console:^6.4 pantheon-systems/drupal-integrations --update-with-all-dependencies -W
        
        # Rebuild caches after updating
        terminus drush "${site_id}.dev" -- cache:rebuild

		terminus env:commit "${site_id}.dev" --message="Update to Drupal 10"
        
        echo -e "${GREEN}Drupal core updated to 10 successfully.${RESET}"
    else
        echo -e "${GREEN}Drupal is already on version 10 or higher. No update needed.${RESET}"
    fi

	terminus connection:set "${site_id}.dev" git -y
}

set_multidev() {
	echo ""
	echo -e "${YELLOW}Set the multidev to test on based on the PR number passed in from CI."


	# Check if multidev exists, create if it does not.
	local multidevs
	multidevs="$(terminus multidev:list "${site_id}" --fields=id --format=list)"
	if echo "${multidevs}" | grep -q "pr-${pr_num}"; then
		echo "Multidev environment for PR ${pr_num} already exists."
	else
		echo "Creating multidev environment for PR ${pr_num}."
		terminus multidev:create "${site_id}".dev "pr-${pr_num}"
	fi

	cd ~/pantheon-local-copies/"${site_id}"
	git fetch --all
	if git show-ref --verify --quiet refs/remotes/origin/pr-"${pr_num}"; then
		echo "Branch pr-${pr_num} exists."
		git checkout -B "pr-${pr_num}" --track origin/"pr-${pr_num}"
	else
		echo -e "${RED}Branch pr-${pr_num} could not be found.${RESET}"
		return 1
	fi

	# Setup the Drupal site.
	terminus drush "${site_id}.pr-${pr_num}" -- si -y
	terminus drush "${site_id}.pr-${pr_num}" -- cr
}

update_pantheon_php_version() {
    local yml_file="$HOME/pantheon-local-copies/${site_id}/pantheon.yml"
    local php_version_with_dot="${PHP_VERSION}"  # Ensure version has the period

    # If pantheon.yml doesn't exist, create it with api_version: 1
    if [[ ! -f "$yml_file" ]]; then
        echo -e "${YELLOW}pantheon.yml does not exist. Creating it.${RESET}"
        echo -e "api_version: 1\nphp_version: ${php_version_with_dot}" > "$yml_file"
        return 0
    fi

    # Check if a php_version line exists
    if grep -q "^php_version:" "$yml_file"; then
        echo -e "php_version found in pantheon.yml."
    else
        echo -e "${YELLOW}Adding php_version to pantheon.yml.${RESET}"
        echo "php_version: ${php_version_with_dot}" >> "$yml_file"
    fi
}

copy_bad_module() {
	echo -e "${YELLOW}Checking if TLS testing module exists...${RESET}"
	if ! terminus drush "${site_id}.pr-${pr_num}" -- pm:list --type=module --field=name | grep -q tls_checker_test; then
		cp -r "${workspace}"/.github/fixtures/tls_checker_test ~/pantheon-local-copies/"${site_id}"/web/modules/custom
	else
		echo "Test module already installed"
	fi
}

copy_pr_updates() {
	echo "Commit message: ${commit_msg}"
	cd ~/pantheon-local-copies/"${site_id}/web/modules/custom"
	echo -e "${YELLOW}Copying latest changes to TLS Checker and committing to the site.${RESET}"
	mkdir -p tls_checker && cd tls_checker
	rsync -a --exclude=".git" "${workspace}/" .
	cd ~/pantheon-local-copies/"${site_id}"
	git add -A
	git commit -m "Update to latest commit: ${commit_msg}" || true
	git push origin "pr-${pr_num}" || true
	terminus workflow:wait "${site_id}.pr-${pr_num}"
}

# Run the steps
cd "${workspace}"
log_into_terminus
create_site
clone_site
set_multidev
update_pantheon_php_version
copy_bad_module
copy_pr_updates
echo -e "${GREEN}Test environment setup complete.${RESET} 🚀"