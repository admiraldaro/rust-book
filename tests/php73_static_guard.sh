#!/bin/sh
set -eu

files="$(find public src scripts config templates -name '*.php' -print)"

check_pattern() {
    pattern="$1"
    label="$2"
    if grep -RInE "$pattern" public src scripts config templates --include='*.php' >/tmp/rust-book-php73-guard.txt 2>/dev/null; then
        cat /tmp/rust-book-php73-guard.txt >&2
        echo "$label" >&2
        exit 1
    fi
}

check_pattern '\bstr_starts_with[[:space:]]*\(' 'str_starts_with is PHP 8-only'
check_pattern '\bstr_ends_with[[:space:]]*\(' 'str_ends_with is PHP 8-only'
check_pattern '\bstr_contains[[:space:]]*\(' 'str_contains is PHP 8-only'
check_pattern '\bmatch[[:space:]]*\(' 'match expression is PHP 8-only'
check_pattern '\?\->' 'nullsafe operator is PHP 8-only'
check_pattern '#\[' 'attributes are PHP 8-only'
check_pattern 'function[[:space:]]+__construct[[:space:]]*\([^)]*\b(public|protected|private)[[:space:]]+\$' 'constructor property promotion is PHP 8-only'

for file in $files; do
    php -l "$file" >/dev/null
done

echo "No obvious PHP 8-only constructs were found, and PHP syntax is valid."
