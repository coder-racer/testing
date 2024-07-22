<?php

/**
 * Class Database
 * Управляет подключением к базе данных с использованием PDO
 */
class Database
{
    private $host = "185.177.216.77:3306";
    private $db_name = "testdatabase";
    private $username = "UGGwno";
    private $password = "yTAauBvKMoUsdrjA";
    public $conn;

    /**
     * Получает соединение с базой данных
     *
     * @return PDO|null
     */
    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        } catch (PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }

    /**
     * Логирует сообщение в файл
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        error_log($message . "\n", 3, 'recruitment.log');
    }
}

?>
