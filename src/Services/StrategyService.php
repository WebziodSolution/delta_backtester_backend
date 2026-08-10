<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class StrategyService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $strategyId): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, name, description FROM strategys WHERE id = :id");
        $stmt->execute(['id' => $strategyId]);
        $strategy = $stmt->fetch();
        
        if (!$strategy) {
            throw new Exception("Strategy with ID {$strategyId} not found", 404);
        }

        // Cast values
        $strategy['id'] = (int)$strategy['id'];

        return $strategy;
    }

    public static function getAll(int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, name, description FROM strategys LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $skip, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll();
        foreach ($results as &$row) {
            $row['id'] = (int)$row['id'];
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $name = ValidationHelper::validateNonEmptyStrip($data['name'] ?? '', 'name');
        
        $description = null;
        if (isset($data['description']) && $data['description'] !== '') {
            $description = trim((string)$data['description']);
        }

        $stmt = $db->prepare("INSERT INTO strategys (name, description) VALUES (:name, :description)");
        $stmt->execute([
            'name' => $name,
            'description' => $description
        ]);

        $newId = (int)$db->lastInsertId();
        return self::getById($newId);
    }

    public static function update(int $strategyId, array $data): array {
        $db = self::getDb();
        $strategy = self::getById($strategyId); // Ensures strategy exists or throws 404

        $updateFields = [];
        $params = ['id' => $strategyId];

        if (isset($data['name'])) {
            $name = ValidationHelper::validateNonEmptyStrip($data['name'], 'name');
            $updateFields[] = "name = :name";
            $params['name'] = $name;
        }

        if (array_key_exists('description', $data)) {
            $description = null;
            if ($data['description'] !== null && $data['description'] !== '') {
                $description = trim((string)$data['description']);
            }
            $updateFields[] = "description = :description";
            $params['description'] = $description;
        }

        if (empty($updateFields)) {
            return $strategy;
        }

        $sql = "UPDATE strategys SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($strategyId);
    }

    public static function delete(int $strategyId): array {
        $strategy = self::getById($strategyId); // Get details to return or throw 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM strategys WHERE id = :id");
        $stmt->execute(['id' => $strategyId]);
        return $strategy;
    }
}
