#!/bin/bash

# This script sets the Telegram bot webhook to the specified URL.

# 1. Prompt for user input
read -p "Enter your domain name (e.g., example.com): " DOMAIN
# Use -sp to make the token input silent (not displayed on the terminal)
read -sp "Enter your Telegram bot token: " BOT_TOKEN
echo "" # Move to a new line after the silent prompt

# 2. Validate input
if [ -z "$DOMAIN" ]; then
  echo "Error: Domain name cannot be empty. Aborting."
  exit 1
fi

if [ -z "$BOT_TOKEN" ]; then
  echo "Error: Bot token cannot be empty. Aborting."
  exit 1
fi

# 3. Construct the webhook and API URLs
WEBHOOK_URL="https://$DOMAIN/AVCPanel/bot.php"
API_URL="https://api.telegram.org/bot$BOT_TOKEN/setWebhook"

# 4. Make the API call and capture the response
# -s flag makes curl silent
RESPONSE=$(curl -s -X POST "$API_URL" -d "url=$WEBHOOK_URL")

# 5. Check the response from the Telegram API
# A successful response from Telegram contains '"ok":true'
if echo "$RESPONSE" | grep -q '"ok":true'; then
  echo "Webhook was set successfully."
else
  echo "Failed to set webhook. Please check your domain and token."
  # Optionally, uncomment the line below to show the error from Telegram for debugging
  # echo "Telegram API response: $RESPONSE"
  exit 1
fi