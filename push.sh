#!/bin/bash

# Get current folder name
FOLDER_NAME=$(basename "$PWD")

# Build GitHub URL automatically
REPO_URL="https://github.com/UnysonPlus/$FOLDER_NAME.git"

# Detect current branch
BRANCH=$(git rev-parse --abbrev-ref HEAD)

# Check for changes
if [ -n "$(git status --porcelain)" ]; then
    # Stage all changes
    git add .

    # Commit changes
    git commit -m "Manifest updated"

    # Push to GitHub
    git push $REPO_URL $BRANCH

    echo "Changes committed and pushed successfully."
else
    echo "No changes to commit."
fi
