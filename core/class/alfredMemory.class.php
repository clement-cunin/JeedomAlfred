<?php

class alfredMemory
{
    /**
     * Save a new memory. $scope must be "global" or "user:{login}".
     * Returns the new memory ID.
     */
    public static function save(string $scope, string $content): int
    {
        DB::Prepare(
            'INSERT INTO alfred_memory (scope, content) VALUES (:scope, :content)',
            [':scope' => $scope, ':content' => $content],
            DB::FETCH_TYPE_ROW
        );
        return (int)DB::getLastInsertId();
    }

    /**
     * Update an existing memory. Enforces that the memory belongs to one of $allowedScopes.
     */
    public static function update(int $id, string $content, array $allowedScopes): void
    {
        $row = self::getById($id);
        if ($row === null || !in_array($row['scope'], $allowedScopes, true)) {
            throw new Exception('Memory #' . $id . ' not found or access denied.');
        }
        DB::Prepare(
            'UPDATE alfred_memory SET content = :content, updated_at = NOW() WHERE id = :id',
            [':content' => $content, ':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    /**
     * Delete a memory. Enforces scope access unless $allowedScopes is null (admin).
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
            'SELECT id, scope, content, created_at FROM alfred_memory'
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
            'SELECT id, scope, content, created_at, updated_at FROM alfred_memory ORDER BY scope, created_at ASC',
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

    private static function getById(int $id): ?array
    {
        $row = DB::Prepare(
            'SELECT id, scope, content FROM alfred_memory WHERE id = :id LIMIT 1',
            [':id' => $id],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }
}
