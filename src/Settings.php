<?php
declare(strict_types=1);

final class Settings
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get($name, $default)
    {
        $stmt = $this->db->pdo()->prepare('SELECT value FROM settings WHERE name = :name');
        $stmt->execute(array(':name' => $name));
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }

    public function getInt($name, $default, $min, $max)
    {
        $value = $this->get($name, (string) $default);
        if (!preg_match('/^-?\d+$/', $value)) {
            return $default;
        }

        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }

        return $int;
    }

    public function set($name, $value)
    {
        $now = $this->db->now();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings(name, value, updated_at) VALUES(:name, :value, :updated_at)
             ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute(array(
            ':name' => $name,
            ':value' => (string) $value,
            ':updated_at' => $now
        ));
    }
}
