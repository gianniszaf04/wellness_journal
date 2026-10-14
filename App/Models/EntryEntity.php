<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class EntryEntity {
    private PDO $db;

    public function __construct() {
        $this->db= (new Database())->getConnection();
    }

    public function createBatch(int $entryId , array $entities):bool {
        if (empty($entities)) {
            return true ;
        }

        $stmt = $this->db->prepare(
        'INSERT IGNORE INTO entry_entities (entry_id, entity_name, entity_type) 
        VALUES (:entry_id, :name, :type)');

        try {
            foreach ($entities as $entity) {
                $name = trim($entity['name'] ?? '');
                $type = trim($entity['type'] ?? 'keyword');

                if($name === '' || strlen($name) > 100 ) {
                    continue;
                }

                if (strlen($type) >50) {
                    $type = substr($type, 0, 50);
                }

                $stmt->execute([
                    ':entry_id' => $entryId,
                    ':name' => $name,
                    ':type'  => $type,
                ]);
            }
        return true;
        }catch(\PDOException $e) {
            error_log('EntryEntity::createBatch error: ' . $e->getMessage());
            return false;
        }
    }

    public function findForEntry(int $entryId): array {
        $stmt=$this->db->prepare(
            'SELECT id, entry_id, entity_name, entity_type, created_at
            FROM entry_entities
            WHERE entry_id = :entry_id
            ORDER BY  entity_type ASC, entity_name ASC
            ');
            $stmt->execute([':entry_id'=>$entryId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteForEntry(int $entryId):bool {
        $stmt = $this->db->prepare('DELETE FROM entry_entities WHERE entry_id = :entry_id');
    
        return $stmt->execute([':entry_id' => $entryId]);
    }

    public function topForUser(int $userId, int $limit = 10 ):array {
        if($limit < 1 || $limit > 1000 ) {
            $limit = 10;
        }

        $stmt =$this->db->prepare(
            'SELECT ee.entity_name, ee.entity_type, COUNT(*) AS mention_count
            FROM  entry_entities ee
            JOIN entries e ON e.id = ee.entry_id
            WHERE e.user_id = :user_id
            GROUP BY ee.entity_name, ee.entity_type
            ORDER BY mention_count DESC
            LIMIT :lim');

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findForUserInRange(int $userId, string $fromDate, string $toDate):array {
        $stmt =$this->db->prepare(
            'SELECT ee.entity_name, ee.entity_type, e.entry_date, COUNT(*) AS mention_count
            FROM  entry_entities ee
            JOIN entries e ON e.id = ee.entry_id
            WHERE e.user_id = :user_id
            AND e.entry_date BETWEEN :from_date AND :to_date
            GROUP BY ee.entity_name, ee.entity_type, e.entry_date
            ORDER BY e.entry_date DESC, mention_count DESC');

            $stmt->execute([
                ':user_id' => $userId,
                ':from_date' => $fromDate,
                ':to_date' => $toDate,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exists(int $entryId, string $entityName, string $entityType): bool {
        $stmt = $this->db->prepare(
            ' SELECT 1 FROM entry_entities
            WHERE entry_id = :entry_id
            AND entity_name = :name 
            AND entity_type = :type
            LIMIT 1');

        $stmt->execute([
            ':entry_id' => $entryId,
            ':name'     => trim($entityName),
            ':type'     => trim($entityType),
        ]);

        return $stmt->fetch() !== false;
    }

    public function delete(int $id) :  bool {

        $stmt=$this->db->prepare('DELETE FROM entry_entities WHERE id = :id');

        return $stmt->execute([':id' => $id]);
    }

    public function  findByName(int $userId, string $entityName):array {
        $stmt= $this->db->prepare(
            'SELECT  ee.id, ee.entity_name, ee.entity_type, e.entry_date
            FROM entry_entities ee
            JOIN entries e on e.id = ee.entry_id
            WHERE e.user_id = :user_id
            AND ee.entity_name = :name 
            ORDER BY e.entry_date DESC
            ');
        $stmt->execute([
            ':user_id' => $userId,
            ':name' =>trim($entityName),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function  findByType(int $userId, string $entityType):array {
        $stmt= $this->db->prepare(
            'SELECT ee.entity_name, COUNT(*) AS mention_count
            FROM entry_entities ee
            JOIN entries e on e.id = ee.entry_id
            WHERE e.user_id = :user_id
            AND ee.entity_type = :type
            GROUP BY ee.entity_name
            ORDER BY mention_count DESC
            ');
        $stmt->execute([
            ':user_id' => $userId,
            ':type' =>trim($entityType),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countForUser(int $userId) : int {
        $stmt=$this->db->prepare(
            'SELECT COUNT(DISTINCT ee.entity_name)
            FROM entry_entities ee
            JOIN entries e ON e.id = ee.entry_id
            WHERE e.user_id = :user_id
            ');
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();    
    }



}