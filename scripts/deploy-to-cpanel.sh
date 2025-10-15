#!/bin/bash

echo "🚀 Deploying to cPanel..."

# Configuration
REMOTE_HOST="yourdomain.com"
REMOTE_USER="your_cpanel_username"
REMOTE_PATH="/home/username/public_html"

# Sync files (excluding development files)
rsync -avz \
  --exclude='.devcontainer/' \
  --exclude='.git/' \
  --exclude='tools/' \
  --exclude='scripts/' \
  --exclude='database/' \
  --exclude='*.sql' \
  ./ $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/

# Set proper permissions
ssh $REMOTE_USER@$REMOTE_HOST "chmod -R 755 $REMOTE_PATH/ && chmod 644 $REMOTE_PATH/config/config.php"

echo "✅ Deployment complete!"
echo "🌐 Your site is live at: https://$REMOTE_HOST"
