<?php
declare(strict_types=1);

final class RustDeskProtocol
{
    public static function normalizeLegacyAddressBook($book)
    {
        if (!is_array($book) || !isset($book['tags']) || !isset($book['peers'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $tags = self::normalizeStringList($book['tags'], 'tags');
        self::assertUniqueStrings($tags, 'tags');

        if (!self::isListArray($book['peers'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $tagColors = array();
        if (isset($book['tag_colors'])) {
            if (!is_string($book['tag_colors'])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $tagColors = self::decodeTagColors($book['tag_colors']);
        }

        $tagIndex = array();
        foreach ($tags as $tag) {
            $tagIndex[$tag] = true;
        }

        $peers = array();
        $ids = array();
        foreach ($book['peers'] as $peer) {
            $normalized = self::normalizeLegacyPeer($peer);
            if (isset($ids[$normalized['id']])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $ids[$normalized['id']] = true;

            foreach ($normalized['tags'] as $tag) {
                if (!isset($tagIndex[$tag])) {
                    $tags[] = $tag;
                    $tagIndex[$tag] = true;
                }
            }

            $peers[] = $normalized;
        }

        $filteredColors = array();
        foreach ($tags as $tag) {
            if (array_key_exists($tag, $tagColors)) {
                $filteredColors[$tag] = $tagColors[$tag];
            }
        }

        return array(
            'tags' => $tags,
            'peers' => $peers,
            'tag_colors_map' => $filteredColors
        );
    }

    public static function legacyBook($tags, $peers, $tagColors)
    {
        return array(
            'tags' => array_values($tags),
            'peers' => array_values($peers),
            'tag_colors' => self::encodeTagColors($tagColors, $tags)
        );
    }

    public static function jsonDecodeObject($json)
    {
        $decoded = json_decode((string) $json, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return $decoded;
    }

    private static function normalizeLegacyPeer($peer)
    {
        if (!is_array($peer) || !isset($peer['id'])) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $id = self::stringValue($peer['id']);
        if (trim($id) === '') {
            throw new AddressBookValidationException('Invalid address book data');
        }

        return array(
            'id' => $id,
            'username' => isset($peer['username']) ? self::stringValue($peer['username']) : '',
            'hostname' => isset($peer['hostname']) ? self::stringValue($peer['hostname']) : '',
            'platform' => isset($peer['platform']) ? self::stringValue($peer['platform']) : '',
            'alias' => isset($peer['alias']) ? self::stringValue($peer['alias']) : '',
            'tags' => isset($peer['tags']) ? self::uniqueList(self::normalizeStringList($peer['tags'], 'peers.tags')) : array(),
            'hash' => isset($peer['hash']) ? self::stringValue($peer['hash']) : ''
        );
    }

    private static function normalizeStringList($value, $field)
    {
        if (!self::isListArray($value)) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $items = array();
        foreach ($value as $item) {
            $items[] = self::stringValue($item);
        }

        return $items;
    }

    private static function stringValue($value)
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new AddressBookValidationException('Invalid address book data');
    }

    private static function assertUniqueStrings($items, $field)
    {
        $seen = array();
        foreach ($items as $item) {
            if (isset($seen[$item])) {
                throw new AddressBookValidationException('Invalid address book data');
            }
            $seen[$item] = true;
        }
    }

    private static function uniqueList($items)
    {
        $seen = array();
        $unique = array();
        foreach ($items as $item) {
            if (!isset($seen[$item])) {
                $unique[] = $item;
                $seen[$item] = true;
            }
        }

        return $unique;
    }

    private static function isListArray($value)
    {
        if (!is_array($value)) {
            return false;
        }

        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    private static function decodeTagColors($json)
    {
        $decoded = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new AddressBookValidationException('Invalid address book data');
        }

        $colors = array();
        foreach ($decoded as $tag => $color) {
            if (!is_string($tag)) {
                throw new AddressBookValidationException('Invalid address book data');
            }

            if (is_int($color)) {
                $colors[$tag] = (string) $color;
            } elseif (is_string($color) && preg_match('/^-?\d+$/', $color)) {
                $colors[$tag] = $color;
            } elseif (is_float($color) && floor($color) === $color && $color >= -2147483648 && $color <= 2147483647) {
                $colors[$tag] = sprintf('%.0f', $color);
            } else {
                throw new AddressBookValidationException('Invalid address book data');
            }
        }

        return $colors;
    }

    private static function encodeTagColors($colors, $tags)
    {
        $parts = array();
        foreach ($tags as $tag) {
            if (!array_key_exists($tag, $colors) || $colors[$tag] === null || $colors[$tag] === '') {
                continue;
            }

            $encodedTag = json_encode((string) $tag, JSON_UNESCAPED_SLASHES);
            if ($encodedTag === false) {
                throw new RuntimeException('Could not encode tag color key.');
            }
            $parts[] = $encodedTag . ':' . (string) $colors[$tag];
        }

        return '{' . implode(',', $parts) . '}';
    }
}
