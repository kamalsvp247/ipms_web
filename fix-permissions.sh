#!/bin/bash

set -e

TARGET_DIR="${1:-/var/www}"
OWNER="www-data"
GROUP="www-data"
FILE_CHMOD="775"
DIR_CHMOD="775"

if [[ ! "$TARGET_DIR" =~ ^/var/www ]]; then
    echo "Error: This script only works within /var/www directory for safety"
    echo "   Requested: $TARGET_DIR"
    exit 1
fi

echo "Fixing permissions in: $TARGET_DIR"
echo "Owner: $OWNER:$GROUP"
echo "Files: $FILE_CHMOD | Directories: $DIR_CHMOD"
echo ""

# Count changes
FILES_CHANGED=0
DIRS_CHANGED=0

# Fix directory permissions
echo "Processing directories..."
while IFS= read -r dir; do
    current_owner=$(stat -c "%U:%G" "$dir" 2>/dev/null || echo "unknown")
    current_chmod=$(stat -c "%a" "$dir" 2>/dev/null || echo "unknown")

    if [[ "$current_owner" != "$OWNER:$GROUP" ]] || [[ "$current_chmod" != "$DIR_CHMOD" ]]; then
        sudo chown "$OWNER:$GROUP" "$dir"
        sudo chmod "$DIR_CHMOD" "$dir"
        ((DIRS_CHANGED++))
    fi
done < <(find "$TARGET_DIR" -type d -not -path '*/\.*' 2>/dev/null)

# Fix file permissions
echo "Processing files..."
while IFS= read -r file; do
    current_owner=$(stat -c "%U:%G" "$file" 2>/dev/null || echo "unknown")
    current_chmod=$(stat -c "%a" "$file" 2>/dev/null || echo "unknown")

    if [[ "$current_owner" != "$OWNER:$GROUP" ]] || [[ "$current_chmod" != "$FILE_CHMOD" ]]; then
        sudo chown "$OWNER:$GROUP" "$file"
        sudo chmod "$FILE_CHMOD" "$file"
        ((FILES_CHANGED++))
    fi
done < <(find "$TARGET_DIR" -type f -not -path '*/\.*' -not -path '*/.git/*' 2>/dev/null)

echo ""
echo "Done!"
echo "Directories fixed: $DIRS_CHANGED"
echo "Files fixed: $FILES_CHANGED"
