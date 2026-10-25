<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/guard_admin.php');

// Pagination settings.
$perPage = 10;

// Determine which page to return to after a POST action.
$requestedPage = $_GET['page'] ?? $_POST['page'] ?? 1;
$requestedPage = filter_var($requestedPage, FILTER_VALIDATE_INT);

if ($requestedPage === false || $requestedPage === null || $requestedPage < 1) {
    $requestedPage = 1;
}

$returnUrl = BASE_URL . '/admin/users.php?page=' . $requestedPage;

// Create a CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = (int) $_SESSION['user_id'];

// Handle account-management actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        set_alert('error', 'Invalid request. Please try again.');
        header('Location: ' . $returnUrl);
        exit;
    }

    $targetUserId = filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (
        !$targetUserId ||
        !in_array(
            $action,
            ['activate', 'deactivate', 'change_role'],
            true
        )
    ) {
        set_alert('error', 'Invalid account-management request.');
        header('Location: ' . $returnUrl);
        exit;
    }

    // Handle role changes.
    if ($action === 'change_role') {
        $newRole = $_POST['role'] ?? '';

        if (!in_array($newRole, ['customer', 'admin'], true)) {
            set_alert('error', 'Invalid role selected.');
            header('Location: ' . $returnUrl);
            exit;
        }

        // Prevent changing your own role.
        if ($targetUserId === $adminId) {
            set_alert('error', 'You cannot change your own role.');
            header('Location: ' . $returnUrl);
            exit;
        }

        mysqli_begin_transaction($conn);

        try {
            // Lock the target account while changing its role.
            $targetResult = mysqli_execute_query(
                $conn,
                "SELECT user_id, role
                 FROM users
                 WHERE user_id = ?
                 FOR UPDATE",
                [$targetUserId]
            );

            $target = $targetResult
                ? mysqli_fetch_assoc($targetResult)
                : null;

            if (!$target) {
                throw new Exception('User account not found.');
            }

            if ($target['role'] === $newRole) {
                mysqli_commit($conn);

                set_alert('success', 'The user already has that role.');
                header('Location: ' . $returnUrl);
                exit;
            }

            // Do not remove the last administrator.
            if ($target['role'] === 'admin' && $newRole === 'customer') {
                $adminsResult = mysqli_query(
                    $conn,
                    "SELECT user_id
                     FROM users
                     WHERE role = 'admin'
                     FOR UPDATE"
                );

                if (!$adminsResult) {
                    throw new Exception(
                        'Unable to verify administrator accounts.'
                    );
                }

                if (mysqli_num_rows($adminsResult) <= 1) {
                    throw new Exception(
                        'You cannot change the role of the last administrator.'
                    );
                }

                // A customer account requires a customer profile.
                $profileResult = mysqli_execute_query(
                    $conn,
                    "SELECT customer_id
                     FROM customer
                     WHERE user_id = ?",
                    [$targetUserId]
                );

                $profile = $profileResult
                    ? mysqli_fetch_assoc($profileResult)
                    : null;

                if (!$profile) {
                    throw new Exception(
                        'This admin has no customer profile. Create the customer profile before changing this role.'
                    );
                }
            }

            $updated = mysqli_execute_query(
                $conn,
                "UPDATE users
                 SET role = ?
                 WHERE user_id = ?",
                [$newRole, $targetUserId]
            );

            if (!$updated) {
                throw new Exception('Unable to update the user role.');
            }

            mysqli_commit($conn);

            set_alert(
                'success',
                'User role changed to ' .
                ($newRole === 'admin' ? 'Admin' : 'Customer') .
                '.'
            );
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            set_alert('error', $e->getMessage());
        }

        header('Location: ' . $returnUrl);
        exit;
    }

    // Activation and deactivation apply to customer accounts only.
    $newStatus = ($action === 'activate') ? 1 : 0;

    $updateResult = mysqli_execute_query(
        $conn,
        "UPDATE users
         SET is_active = ?
         WHERE user_id = ?
           AND role = 'customer'",
        [$newStatus, $targetUserId]
    );

    if ($updateResult) {
        $check = mysqli_execute_query(
            $conn,
            "SELECT is_active
             FROM users
             WHERE user_id = ?
               AND role = 'customer'",
            [$targetUserId]
        );

        $customer = $check
            ? mysqli_fetch_assoc($check)
            : null;

        if (
            $customer &&
            (int) $customer['is_active'] === $newStatus
        ) {
            set_alert(
                'success',
                $newStatus
                    ? 'Customer account is active.'
                    : 'Customer account is deactivated.'
            );
        } else {
            set_alert(
                'error',
                'Unable to update the customer account. It may not be a customer account.'
            );
        }
    } else {
        set_alert('error', 'Unable to update the customer account.');
    }

    header('Location: ' . $returnUrl);
    exit;
}

// Count all user accounts for pagination.
$countResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total_users FROM users"
);

if (!$countResult) {
    die('Unable to count users.');
}

$countRow = mysqli_fetch_assoc($countResult);
$totalUsers = (int) $countRow['total_users'];

$totalPages = max(1, (int) ceil($totalUsers / $perPage));

// Keep the requested page within the valid range.
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch only the users needed for the current page.
$result = mysqli_execute_query(
    $conn,
    "SELECT
        u.user_id,
        u.username,
        u.role,
        u.is_active,
        u.password_changed_at,
        c.fname,
        c.lname,
        c.contact
     FROM users u
     LEFT JOIN customer c ON c.user_id = u.user_id
     ORDER BY u.role, c.lname, u.username
     LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

if (!$result) {
    die('Unable to load users.');
}

// Calculate the displayed range.
$firstUser = $totalUsers > 0 ? $offset + 1 : 0;
$lastUser = min($offset + $perPage, $totalUsers);

include('../includes/admin_header.php');
?>

<h1>User Management</h1>

<p>
    Showing
    <strong><?= $firstUser ?>–<?= $lastUser ?></strong>
    of
    <strong><?= $totalUsers ?></strong>
    user account(s).
</p>

<div style="overflow-x: auto;">
    <table class="cart-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Contact</th>
                <th>Role</th>
                <th>Account Status</th>
                <th>Last Password Change</th>
                <th>Change Role</th>
                <th>Account Action</th>
            </tr>
        </thead>

        <tbody>
            <?php if ($totalUsers === 0): ?>
                <tr>
                    <td colspan="8">No user accounts found.</td>
                </tr>
            <?php else: ?>
                <?php while ($user = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <?php
                            $fullName = trim(
                                ($user['fname'] ?? '') . ' ' .
                                ($user['lname'] ?? '')
                            );
                            ?>

                            <?= htmlspecialchars(
                                $fullName !== ''
                                    ? $fullName
                                    : 'No customer profile'
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($user['username']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($user['contact'] ?? '—') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(ucfirst($user['role'])) ?>
                        </td>

                        <td>
                            <?= (int) $user['is_active'] === 1
                                ? 'Active'
                                : 'Deactivated' ?>
                        </td>

                        <td>
                            <?php if (!empty($user['password_changed_at'])): ?>
                                <?php
                                $timestamp = strtotime(
                                    $user['password_changed_at']
                                );
                                ?>

                                <?= $timestamp !== false
                                    ? htmlspecialchars(
                                        date('M j, Y g:i A', $timestamp)
                                    )
                                    : 'Not recorded' ?>
                            <?php else: ?>
                                Not recorded
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ((int) $user['user_id'] !== $adminId): ?>
                                <form
                                    method="post"
                                    action="<?= BASE_URL ?>/admin/users.php?page=<?= $page ?>"
                                    onsubmit="return confirm('Are you sure you want to change this user role?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $_SESSION['csrf_token']
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int) $user['user_id'] ?>"
                                    >

                                    <select name="role" required>
                                        <option
                                            value="customer"
                                            <?= $user['role'] === 'customer'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Customer
                                        </option>

                                        <option
                                            value="admin"
                                            <?= $user['role'] === 'admin'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            Admin
                                        </option>
                                    </select>

                                    <button
                                        type="submit"
                                        name="action"
                                        value="change_role"
                                    >
                                        Update Role
                                    </button>
                                </form>
                            <?php else: ?>
                                Your account
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($user['role'] === 'customer'): ?>
                                <form
                                    method="post"
                                    action="<?= BASE_URL ?>/admin/users.php?page=<?= $page ?>"
                                    onsubmit="return confirm('Are you sure you want to change this account status?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $_SESSION['csrf_token']
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int) $user['user_id'] ?>"
                                    >

                                    <?php if ((int) $user['is_active'] === 1): ?>
                                        <button
                                            type="submit"
                                            name="action"
                                            value="deactivate"
                                        >
                                            Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button
                                            type="submit"
                                            name="action"
                                            value="activate"
                                        >
                                            Activate
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <?php
    // Show a small range around the current page.
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    ?>

    <nav
        aria-label="User pagination"
        style="display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; margin: 24px 0;"
    >
        <?php if ($page > 1): ?>
            <a
                href="<?= BASE_URL ?>/admin/users.php?page=<?= $page - 1 ?>"
                style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none;"
            >
                &laquo; Previous
            </a>
        <?php endif; ?>

        <?php if ($startPage > 1): ?>
            <a
                href="<?= BASE_URL ?>/admin/users.php?page=1"
                style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none;"
            >1</a>

            <?php if ($startPage > 2): ?>
                <span>…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span
                    aria-current="page"
                    style="padding: 8px 12px; border: 1px solid #333; border-radius: 4px; font-weight: bold;"
                >
                    <?= $i ?>
                </span>
            <?php else: ?>
                <a
                    href="<?= BASE_URL ?>/admin/users.php?page=<?= $i ?>"
                    style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none;"
                >
                    <?= $i ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <span>…</span>
            <?php endif; ?>

            <a
                href="<?= BASE_URL ?>/admin/users.php?page=<?= $totalPages ?>"
                style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none;"
            >
                <?= $totalPages ?>
            </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a
                href="<?= BASE_URL ?>/admin/users.php?page=<?= $page + 1 ?>"
                style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none;"
            >
                Next &raquo;
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php include('../includes/footer.php'); ?>