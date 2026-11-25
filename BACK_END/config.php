<?php
declare(strict_types=1);

// XAMPP defaults. Change these only if your MySQL setup is different.
const DB_HOST = '127.0.0.1';
const DB_PORT = 3307;   // XAMPP default. Change it only if the XAMPP panel shows another MySQL port.
const DB_NAME = 'ukayarn';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
