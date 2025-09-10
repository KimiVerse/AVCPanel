#!/bin/bash

# Script to deploy the AVC Panel
# This script copies the project files to your web server's root directory.

# Prompt the user for the domain name
read -p "Please enter your domain name (e.g., example.com): " DOMAIN

# Check if the domain input is empty
if [ -z "$DOMAIN" ]; then
  echo "Error: Domain name cannot be empty. Aborting script."
  exit 1
fi

# Define variables
DEST_PATH="/www/wwwroot/$DOMAIN/AVCPanel"
REPO_URL="https://github.com/KimiVerse/AVCPanel.git"
TEMP_DIR="/tmp/avcpanel_clone_$(date +%s)"

# List of files and directories to be excluded/deleted
FILES_TO_EXCLUDE=(
  ".git"
  "README.md"
  "DEPLOYMENT.md"
  "CODE_OF_CONDUCT.md"
  ".gitignore"
  "LICENSE"
  "cloning.sh" 
  "header.png"
)

# 1. Clone the project into a temporary directory quietly
git clone --depth 1 --quiet "$REPO_URL" "$TEMP_DIR"
if [ $? -ne 0 ]; then
    echo "Error: Failed to clone the repository."
    exit 1
fi

# 2. Remove specified files and directories
for item in "${FILES_TO_EXCLUDE[@]}"; do
  if [ -e "$TEMP_DIR/$item" ]; then
    rm -rf "$TEMP_DIR/$item"
  fi
done

# 3. Create the destination directory if it doesn't exist
mkdir -p "$DEST_PATH"

# 4. Move the remaining files to the final destination
shopt -s dotglob
mv "$TEMP_DIR"/* "$DEST_PATH/"
shopt -u dotglob

# 5. Clean up the temporary directory
rm -rf "$TEMP_DIR"

echo "Deployment completed successfully!"
echo "Project files are now available at: $DEST_PATH"