#!/bin/bash

###############################################################################
# InterSoccer Player Birthdays - Deployment Script
###############################################################################
#
# Usage:
#   ./deploy.sh                 # Deploy to testing site (runs PHPUnit first)
#   ./deploy.sh --no-cache      # Deploy and clear server caches
#   ./deploy.sh --dry-run       # Show what would be uploaded
#
###############################################################################

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SERVER_USER="your-username"
SERVER_HOST="intersoccer.legit.ninja"
SERVER_PATH="/path/to/wordpress/wp-content/plugins/intersoccer-player-birthdays"
SSH_PORT="22"
SSH_KEY="~/.ssh/id_rsa"

if [ -f "deploy.local.sh" ]; then
	# shellcheck source=/dev/null
	source deploy.local.sh
	echo -e "${GREEN}✓ Loaded local configuration${NC}"
fi

DRY_RUN=false
CLEAR_CACHE=false

while [[ $# -gt 0 ]]; do
	case $1 in
		--dry-run)
			DRY_RUN=true
			shift
			;;
		--no-cache|--clear-cache)
			CLEAR_CACHE=true
			shift
			;;
		--help|-h)
			echo "Usage: $0 [--dry-run] [--clear-cache]"
			echo "PHPUnit always runs before deployment."
			exit 0
			;;
		*)
			echo -e "${RED}Unknown option: $1${NC}"
			exit 1
			;;
	esac
done

if [ "$SERVER_USER" = "your-username" ]; then
	echo -e "${RED}✗ Configuration not set. Copy deploy.local.sh.example to deploy.local.sh${NC}"
	exit 1
fi

print_header() {
	echo ""
	echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
	echo -e "${BLUE}  $1${NC}"
	echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
	echo ""
}

run_phpunit_tests() {
	print_header "Running PHPUnit Tests"

	if [ ! -f "vendor/bin/phpunit" ]; then
		if command -v composer >/dev/null 2>&1; then
			echo -e "${YELLOW}⚠ PHPUnit not installed. Installing dev dependencies...${NC}"
			composer install --no-interaction --prefer-dist
		else
			echo -e "${RED}✗ PHPUnit not installed and composer not found.${NC}"
			return 1
		fi
	fi

	vendor/bin/phpunit -c phpunit.xml
}

deploy_to_server() {
	print_header "Deploying to Server"

	if [ -z "$SERVER_PATH" ]; then
		echo -e "${RED}✗ SERVER_PATH is not set${NC}"
		exit 1
	fi

	if [[ ! "$SERVER_PATH" =~ intersoccer-player-birthdays/?$ ]]; then
		echo -e "${RED}✗ SERVER_PATH must end with intersoccer-player-birthdays${NC}"
		exit 1
	fi

	echo -e "Target: ${GREEN}${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}${NC}"

	RSYNC_CMD="rsync -avz"
	if [ "$DRY_RUN" = true ]; then
		RSYNC_CMD="$RSYNC_CMD --dry-run"
	fi
	RSYNC_CMD="$RSYNC_CMD -e 'ssh -p ${SSH_PORT} -i ${SSH_KEY}'"
	RSYNC_CMD="$RSYNC_CMD --include='README.md'"
	RSYNC_CMD="$RSYNC_CMD \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='vendor' \
		--exclude='tests' \
		--exclude='.phpunit.result.cache' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='phpunit.xml' \
		--exclude='*.log' \
		--exclude='*.sh' \
		--exclude='*.md' \
		--exclude='.DS_Store'"

	RSYNC_CMD="$RSYNC_CMD ./ ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/"
	eval "$RSYNC_CMD"
}

print_header "InterSoccer Player Birthdays Deployment"

run_phpunit_tests

if [ "$DRY_RUN" = true ]; then
	echo -e "${YELLOW}DRY RUN MODE - Skipping deployment${NC}"
	exit 0
fi

deploy_to_server

if [ "$CLEAR_CACHE" = true ]; then
	print_header "Clearing Server Caches"
	ssh -p "${SSH_PORT}" -i "${SSH_KEY}" "${SERVER_USER}@${SERVER_HOST}" "php -r 'if (function_exists(\"opcache_reset\")) { opcache_reset(); }'"
fi

print_header "Deployment Complete"
echo -e "${GREEN}✓ Plugin deployed to ${SERVER_HOST}${NC}"
