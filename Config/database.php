<?php
declare(strict_types=1);
/*
use PDO;
use PDOException;
use RuntimeException;
*/
class Database
{
    private string $host;
    private string $dbname;
    private string $username;
    private string $password;
    private ?PDO $conn = null;

    public function __construct()
    {
        $this->validateConfig();

        $this->host =(string) getenv('DB_HOST');
        $this->dbname = (string)getenv('DB_NAME');
        $this->username = (string) getenv('DB_USER'); 
        $this->password = (string) getenv('DB_PASS'); 

    }

    private function validateConfig() :void {
        $required=[ 'DB_HOST' , 'DB_NAME', 'DB_USER' ,'DB_PASS'];

        foreach ($required as $variable) {
            if(getenv($variable)===false || trim((string)getenv($variable)) === '') {
                error_log("Missing required enviromment variable: {$variable}");
                throw new RuntimeException("προέκυψε κρίσιμο σφάλμα ρυθμίσεων. Έλεγχος στα logs του server.");
            }
        }
    }

    public function getConnection(): PDO
    {

        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            // ιδιο offset με την PHP, ωστε CURDATE() και date() να συμφωνουν για το ποια μερα ειναι
            $this->conn->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");

            return $this->conn;
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection error');
        }
    }
}
