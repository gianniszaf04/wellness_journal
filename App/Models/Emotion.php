<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class Emotion{
    private PDO $db;

    public function __construct() {
        $this->db=(new Database())->getConnection();
    }


    public function allActive(): array {
        $stmt = $this->db->query(
            'SELECT id,name , color , emoji , description FROM emotions WHERE is_active=1 ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }


    public function findById(int $id): ?array {
        $stmt=$this->db->prepare('SELECT * FROM emotions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }


    public function findByName(string $name) : ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM emotions WHERE processed_name= LOWER(TRIM(:name)) LIMIT 1'
        );

        $stmt->execute([':name' =>$name]);
        $result=$stmt->fetch();

        return $result ?:null;
    }
}