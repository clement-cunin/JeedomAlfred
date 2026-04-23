<?php

class alfredMemory
{
    /**
     * Save a new memory. $scope must be "global" or "user:{login}".
     * $label is a short text identifier (e.g. "vacation-july-2026").
     * Returns the new memory ID.
     */
    public static function save(string $scope, string $label, string $content): int
    {
        DB::Prepare(
            'INSERT INTO alfred_memory (scope, label, content) VALUES (:scope, :label, :content)',
            [':scope' => $scope, ':label' => $label, ':content' => $content],
            DB::FETCH_TYPE_ROW
        );
        return (int)DB::getLastInsertId();
    }

    /**
     * Update content of a memory by label. Enforces scope unless $allowedScopes is null (admin).
     */
    public static function updateByLabel(string $label, string $content, ?array $allowedScopes = null): void
    {
        $row = self::getByLabel($label);
        if ($row === null) {
            throw new Exception("Memory '{$label}' not found.");
        }
        if ($allowedScopes !== null && !in_array($row['scope'], $allowedScopes, true)) {
            throw new Exception("Memory '{$label}' access denied.");
        }
        DB::Prepare(
            'UPDATE alfred_memory SET content = :content, updated_at = NOW() WHERE id = :id',
            [':content' => $content, ':id' => $row['id']],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Delete a memory by label. Enforces scope unless $allowedScopes is null (admin).
     */
    public static function forgetByLabel(string $label, ?array $allowedScopes = null): void
    {
        $row = self::getByLabel($label);
        if ($row === null) {
            throw new Exception("Memory '{$label}' not found.");
        }
        if ($allowedScopes !== null && !in_array($row['scope'], $allowedScopes, true)) {
            throw new Exception("Memory '{$label}' access denied.");
        }
        DB::Prepare(
            'DELETE FROM alfred_memory WHERE id = :id',
            [':id' => $row['id']],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Admin: update content, scope and label of a memory by numeric ID.
     */
    public static function adminUpdate(int $id, string $label, string $content, string $scope): void
    {
        if (self::getById($id) === null) {
            throw new Exception('Memory #' . $id . ' not found.');
        }
        DB::Prepare(
            'UPDATE alfred_memory SET label = :label, content = :content, scope = :scope, updated_at = NOW() WHERE id = :id',
            [':label' => $label, ':content' => $content, ':scope' => $scope, ':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Admin: delete a memory by numeric ID (no scope restriction).
     */
    public static function forget(int $id, ?array $allowedScopes = null): void
    {
        if ($allowedScopes !== null) {
            $row = self::getById($id);
            if ($row === null || !in_array($row['scope'], $allowedScopes, true)) {
                throw new Exception('Memory #' . $id . ' not found or access denied.');
            }
        }
        DB::Prepare(
            'DELETE FROM alfred_memory WHERE id = :id',
            [':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Load all memories visible to a user: global + user:{login}.
     */
    public static function loadForUser(string $login): array
    {
        return DB::Prepare(
            'SELECT id, scope, label, content, created_at FROM alfred_memory'
            . ' WHERE scope IN (:g, :u) ORDER BY created_at ASC',
            [':g' => 'global', ':u' => 'user:' . $login],
            DB::FETCH_TYPE_ALL
        ) ?: [];
    }

    /**
     * Load all memories regardless of scope (admin view).
     */
    public static function loadAll(): array
    {
        return DB::Prepare(
            'SELECT id, scope, label, content, created_at, updated_at FROM alfred_memory ORDER BY scope, created_at ASC',
            [],
            DB::FETCH_TYPE_ALL
        ) ?: [];
    }

    /**
     * Returns the scopes a user is allowed to write/update/delete.
     * null login → only global (scheduled/background context).
     */
    public static function allowedScopes(?string $userLogin): array
    {
        $scopes = ['global'];
        if ($userLogin !== null) {
            $scopes[] = 'user:' . $userLogin;
        }
        return $scopes;
    }

    // -------------------------------------------------------------------------

    public static function getByLabel(string $label): ?array
    {
        $row = DB::Prepare(
            'SELECT id, scope, label, content FROM alfred_memory WHERE label = :label LIMIT 1',
            [':label' => $label],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    private static function getById(int $id): ?array
    {
        $row = DB::Prepare(
            'SELECT id, scope, label, content FROM alfred_memory WHERE id = :id LIMIT 1',
            [':id' => $id],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }
}
