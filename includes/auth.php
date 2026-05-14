<?php

declare(strict_types=1);

function cmc_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return null;
    }
    $id = (int) $id;
    if ($id < 1) {
        return null;
    }

    $st = cmc_db()->prepare(
        'SELECT u.id, u.email, u.full_name, u.role, u.organisation_id, u.department_id,
                o.name AS organisation_name, d.name AS department_name
         FROM users u
         LEFT JOIN organisations o ON o.id = u.organisation_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function cmc_require_login(): array
{
    $u = cmc_user();
    if ($u === null) {
        cmc_redirect('index.php');
    }
    return $u;
}

/** @param list<string> $roles */
function cmc_require_roles(array $roles): array
{
    $u = cmc_require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $u;
}

function cmc_login(string $email, string $password): bool
{
    $st = cmc_db()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $st->execute([mb_strtolower(trim($email))]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    return true;
}

function cmc_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function cmc_user_count(): int
{
    return (int) cmc_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
