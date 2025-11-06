#!/bin/bash

# Dodo Payments for WooCommerce Plugin Zip Verification Script
# This script verifies the contents of the WordPress plugin zip file

# Exit if any command fails
set -e

# Color codes for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to display messages
function echo_message() {
    echo -e "${GREEN}[VERIFY]${NC} $1"
}

# Function to display warnings
function echo_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Function to display errors
function echo_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if version parameter is provided
if [ -z "$1" ]; then
    # Try to extract version from main plugin file
    VERSION=$(grep -o "Version: [0-9.]*" dodo-payments-for-woocommerce.php | awk '{print $2}')
    
    if [ -z "$VERSION" ]; then
        echo_error "No version provided and couldn't extract version from dodo-payments-for-woocommerce.php"
        echo "Usage: ./verify-zip.sh [version]"
        exit 1
    else
        echo_warning "No version provided, using version from plugin file: $VERSION"
    fi
else
    VERSION="$1"
fi

# Define zip filename with version
ZIP_FILE="build/dodo-payments-for-woocommerce-v$VERSION.zip"

# Check if zip file exists
if [ ! -f "$ZIP_FILE" ]; then
    echo_error "Zip file not found: $ZIP_FILE"
    echo_error "Run ./build-plugin.sh $VERSION first"
    exit 1
fi

echo_message "Verifying zip file: $ZIP_FILE"

# Create verification directory
TEMP_DIR="build/verify"
# Remove previous verification directory if it exists
if [ -d "$TEMP_DIR" ]; then
    echo_message "Removing previous verification directory..."
    rm -rf "$TEMP_DIR"
fi
mkdir -p "$TEMP_DIR"
echo_message "Created verification directory: $TEMP_DIR"

# Extract zip file to verification directory
unzip -q "$ZIP_FILE" -d "$TEMP_DIR"

# The plugin files should be in classic-monks/ directory
PLUGIN_DIR="$TEMP_DIR/dodo-payments-for-woocommerce"

# Check if the plugin directory exists
if [ ! -d "$PLUGIN_DIR" ]; then
    echo_error "Plugin directory not found in zip file"
    echo_error "Expected directory structure: dodo-payments-for-woocommerce/"
    exit 1
fi

# Verify essential files
echo_message "Verifying essential files..."
ESSENTIAL_FILES=("dodo-payments-for-woocommerce.php" "readme.txt" "uninstall.php" "LICENSE.txt")
MISSING_FILES=()

for file in "${ESSENTIAL_FILES[@]}"; do
    if [ ! -f "$PLUGIN_DIR/$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo_error "Missing essential files:"
    for file in "${MISSING_FILES[@]}"; do
        echo_error "- $file"
    done
else
    echo_message "✅ All essential files present"
fi

# Verify version in plugin file
PLUGIN_VERSION=$(grep -o "Version: [0-9.]*" "$PLUGIN_DIR/dodo-payments-for-woocommerce.php" | awk '{print $2}')
if [ "$PLUGIN_VERSION" != "$VERSION" ]; then
    echo_error "Version mismatch in plugin file:"
    echo_error "- Expected: $VERSION"
    echo_error "- Found: $PLUGIN_VERSION"
else
    echo_message "✅ Plugin version matches: $VERSION"
fi

# Verify version in readme.txt
README_VERSION=$(grep -o "Stable tag: [0-9.]*" "$PLUGIN_DIR/readme.txt" | awk '{print $3}')
if [ "$README_VERSION" != "$VERSION" ]; then
    echo_error "Version mismatch in readme.txt:"
    echo_error "- Expected: $VERSION"
    echo_error "- Found: $README_VERSION"
else
    echo_message "✅ Readme stable tag matches: $VERSION"
fi

# Check for unwanted files/directories
echo_message "Checking for unwanted files/directories..."
UNWANTED_PATTERNS=(
    ".git"
    ".github"
    ".vscode"
    ".idea"
    ".cursor"
    ".DS_Store"
    "__MACOSX"
    "node_modules"
    "reference-files"
    "postponed-features"
    "licensemonks-sdk--OLD"
    "licensemonks-sdk--old"
    "dist"
    "vendor"
    "tasks"
    "debug.log"
    "wp-config.php"
    ".env"
    ".windsurfrules"
    ".cursorignore"
    ".cursorrules"
    ".gitignore"
    ".prettierrc"
    "TASKS.md"
    "HASH-URL.md"
    "dodo-payments-for-woocommerce-features.md"
    "IMPLEMENTATION-EXAMPLE.md"
    "REUSABLE-BUILD-PROMPT.md"
    "bricks-inner-panel.php"
    ".ai-context.php"
    "docs"
    "csv"
    "*.md"
    "changelog-input.md"
    "changelog-html.md"
    "cursor-changelog-output.md"
    "generate-changelog.sh"
    ".windsurf"
    ".trae"
    ".mcp.json"
    ".kilocode"
    "py-tools"
    "scripts"
    "tests"
    "GEMINI.md"
    "git-commits-history.md"
    "commits.txt"
    ".claude"
    "site-changelog.md"
    "prompts"
    "build-plugin"
    "qwen"
    ".codexignore"
    ".kilocodeignore"
)

FOUND_UNWANTED=false
for pattern in "${UNWANTED_PATTERNS[@]}"; do
    FOUND=$(find "$PLUGIN_DIR" -name "$pattern" -type d -o -name "$pattern" -type f)
    if [ ! -z "$FOUND" ]; then
        echo_error "Found unwanted pattern: $pattern"
        echo "$FOUND" | sed 's|'"$PLUGIN_DIR"'||g' | sed 's|^|  |'
        FOUND_UNWANTED=true
    fi
done

if [ "$FOUND_UNWANTED" = false ]; then
    echo_message "✅ No unwanted files/directories found"
fi

# Check specifically for MD files
echo_message "Checking for Markdown (.md) files..."
MD_FILES=$(find "$PLUGIN_DIR" -name "*.md")
if [ ! -z "$MD_FILES" ]; then
    echo_error "Found Markdown files that should be excluded:"
    echo "$MD_FILES" | sed 's|'"$PLUGIN_DIR"'||g' | sed 's|^|  |'
else
    echo_message "✅ No Markdown (.md) files found"
fi

# Check for minified JS files without source
echo_message "Checking for minified JS files without source..."
MINIFIED_JS=$(find "$PLUGIN_DIR" -name "*.min.js")
MISSING_SOURCE=()

for minjs in $MINIFIED_JS; do
    # Get the corresponding non-minified JS file
    srcjs="${minjs/.min.js/.js}"
    if [ ! -f "$srcjs" ]; then
        MISSING_SOURCE+=("${minjs/$PLUGIN_DIR\//}")
    fi
done

if [ ${#MISSING_SOURCE[@]} -gt 0 ]; then
    echo_warning "Found minified JS files without source:"
    for file in "${MISSING_SOURCE[@]}"; do
        echo_warning "- $file"
    done
    echo_warning "Ensure non-minified versions are available or linked in readme.txt"
else
    echo_message "✅ All minified JS files have corresponding source files"
fi

# Check zip size
ZIP_SIZE_BYTES=$(stat -f%z "$ZIP_FILE")
ZIP_SIZE_MB=$(echo "scale=2; $ZIP_SIZE_BYTES/1048576" | bc)
echo_message "Zip file size: $ZIP_SIZE_MB MB"

if (( $(echo "$ZIP_SIZE_MB > 10" | bc -l) )); then
    echo_error "❌ Zip file is larger than 10MB (WordPress.org limit)"
else
    echo_message "✅ Zip file size is under 10MB WordPress.org limit"
fi

# List directory structure (top level)
echo_message "Top-level directory structure:"
find "$PLUGIN_DIR" -maxdepth 1 -not -path "$PLUGIN_DIR" | sort | sed 's|'"$PLUGIN_DIR"'||g' | sed 's|^|  |'

# Clean up verification directory
echo_message "Cleaning up verification directory..."
rm -rf "$TEMP_DIR"

echo_message "Verification complete!" 