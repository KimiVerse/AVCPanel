#!/bin/bash

# This script sets the Telegram bot webhook to the specified URL.

echo "This script will help set your Telegram webhook."
echo "---"

# 1. Prompt for the domain name
read -p "Enter your domain name (e.g., example.com): " DOMAIN

# 2. Validate domain input
if [ -z "$DOMAIN" ]; then
  echo "Error: Domain name cannot be empty. Aborting."
  exit 1
fi

# 3. Explain the token prompt and then ask for it
echo ""
echo "Next, please provide your Telegram bot token."
echo "For your security, your typing will not be visible on the screen."
read -p "Enter your token and press Enter: " BOT_TOKEN
echo "" # Move to a new line after the silent prompt
echo "---"

# 4. Validate token input
if [ -z "$BOT_TOKEN" ]; then
  echo "Error: Bot token was not provided. Aborting."
  exit 1
fi

# 5. Construct the webhook and API URLs
WEBHOOK_URL="https://$DOMAIN/AVCPanel/bot.php"
API_URL="https://api.telegram.org/bot$BOT_TOKEN/setWebhook"

# 6. Make the API call and capture the response
RESPONSE=$(curl -s -X POST "$API_URL" -d "url=$WEBHOOK_URL")

# 7. Check the response from the Telegram API
if echo "$RESPONSE" | grep -q '"ok":true'; then
  echo "Success! The webhook was set correctly."
else
  echo "Error: Failed to set the webhook. Please double-check your domain and token."
  # For debugging, you can show the API response:
  # echo "Telegram API response: $RESPONSE"
  exit 1
fi