<?php

function normalizeEmailValue(?string $value): string
{
    return strtolower(trim((string) $value));
}

function fetchUserEmailById(PDO $conn, int $userId): ?string
{
    $statement = $conn->prepare(
        "SELECT `email`
         FROM `users`
         WHERE `id` = :id
         LIMIT 1"
    );
    $statement->execute([
        'id' => $userId,
    ]);

    $email = normalizeEmailValue($statement->fetchColumn() ?: '');

    return $email !== '' ? $email : null;
}

function isUserTryingToReviewOwnRequest(PDO $conn, int $userId, ?string $requestEmployeeEmail): bool
{
    $requestOwnerEmail = normalizeEmailValue($requestEmployeeEmail);

    if ($requestOwnerEmail === '') {
        return false;
    }

    $reviewerEmail = fetchUserEmailById($conn, $userId);

    if ($reviewerEmail === null) {
        return false;
    }

    return $reviewerEmail === $requestOwnerEmail;
}
