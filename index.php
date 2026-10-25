
<?php
include('includes/config.php');
include('includes/alert.php');

$search = trim($_GET['search'] ?? '');
$category_id = $_GET['category_id'] ?? '';

$categories_result = mysqli_query(
    $conn,
    "SELECT category_id, category_name
     FROM category
     ORDER BY category_name"
);

$sql = "SELECT i.item_id, i.item_name, i.price, i.image_path,
               c.category_name, s.quantity
        FROM item i
        JOIN category c ON c.category_id = i.category_id
        JOIN stock s ON s.item_id = i.item_id
        WHERE i.is_hidden = 0";

$params = [];
$types = '';

if ($category_id !== '') {
    $sql .= " AND i.category_id = ?";
    $params[] = $category_id;
    $types .= 'i';
}

if ($search !== '') {
    $sql .= " AND i.item_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$sql .= " ORDER BY i.item_name";

if ($params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $items_result = mysqli_stmt_get_result($stmt);
} else {
    $items_result = mysqli_query($conn, $sql);
}

// Fetch featured products.
$featured_result = mysqli_query($conn, "
    SELECT i.item_id, i.item_name, i.price, i.image_path
    FROM item i
    JOIN stock s ON s.item_id = i.item_id
    WHERE i.is_featured = 1
      AND i.is_hidden = 0
      AND s.quantity > 0
");

$featured_items = [];

while ($row = mysqli_fetch_assoc($featured_result)) {
    $featured_items[] = $row;
}

include('includes/header.php');
?>

<h1>Shop</h1>

<?php if ($featured_items): ?>
<div class="carousel" id="featuredCarousel">
    <div class="carousel-track">
        <?php foreach ($featured_items as $f): ?>
            <div class="carousel-slide">
                <a
                    href="<?= BASE_URL ?>/product_details.php?item_id=<?= (int) $f['item_id'] ?>"
                    aria-label="View <?= htmlspecialchars($f['item_name']) ?> details"
                    style="color: inherit; text-decoration: none;"
                >
                    <img
                        src="<?= BASE_URL ?>/item/images/<?= htmlspecialchars($f['image_path']) ?>"
                        alt="<?= htmlspecialchars($f['item_name']) ?>"
                    >

                    <div class="carousel-caption">
                        <h3><?= htmlspecialchars($f['item_name']) ?></h3>
                        <p>₱<?= number_format((float) $f['price'], 2) ?></p>
                        <span>View Product Details</span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function() {
    const track = document.querySelector(
        '#featuredCarousel .carousel-track'
    );
    const slides = document.querySelectorAll(
        '#featuredCarousel .carousel-slide'
    );

    if (!track || slides.length <= 1) return;

    let index = 0;
    let paused = false;

    function show(i) {
        track.style.transform = 'translateX(-' + (i * 100) + '%)';
    }

    setInterval(function() {
        if (paused) return;

        index = (index + 1) % slides.length;
        show(index);
    }, 3500);

    const el = document.getElementById('featuredCarousel');

    el.addEventListener('mouseenter', function() {
        paused = true;
    });

    el.addEventListener('mouseleave', function() {
        paused = false;
    });
})();
</script>
<?php endif; ?>

<form method="get" action="" class="filter-bar">
    <input
        type="text"
        name="search"
        placeholder="Search items..."
        value="<?= htmlspecialchars($search) ?>"
    >

    <select name="category_id">
        <option value="">All Categories</option>

        <?php while ($cat = mysqli_fetch_assoc($categories_result)): ?>
            <option
                value="<?= (int) $cat['category_id'] ?>"
                <?= ($category_id == $cat['category_id']) ? 'selected' : '' ?>
            >
                <?= htmlspecialchars($cat['category_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>

    <button type="submit">Filter</button>
</form>

<div class="item-grid">
    <?php while ($item = mysqli_fetch_assoc($items_result)): ?>
        <?php
        $product_url = BASE_URL . '/product_details.php?item_id='
            . (int) $item['item_id'];
        ?>

        <div class="item-card">
            <a
                href="<?= htmlspecialchars($product_url) ?>"
                aria-label="View <?= htmlspecialchars($item['item_name']) ?> details"
                style="color: inherit; text-decoration: none;"
            >
                <img
                    src="<?= BASE_URL ?>/item/images/<?= htmlspecialchars($item['image_path']) ?>"
                    alt="<?= htmlspecialchars($item['item_name']) ?>"
                >

                <h3><?= htmlspecialchars($item['item_name']) ?></h3>
            </a>

            <p class="item-category">
                <?= htmlspecialchars($item['category_name']) ?>
            </p>

            <p class="item-price">
                ₱<?= number_format((float) $item['price'], 2) ?>
            </p>

            

            <?php if ((int) $item['quantity'] <= 0): ?>
                <p class="out-of-stock">Out of Stock</p>
                <button type="button" disabled>Add to Cart</button>
            <?php else: ?>
                <form method="post" action="<?= BASE_URL ?>/cart_update.php">
                    <input type="hidden" name="action" value="add">

                    <input
                        type="hidden"
                        name="item_id"
                        value="<?= (int) $item['item_id'] ?>"
                    >

                    <input
                        type="number"
                        name="quantity"
                        value="1"
                        min="1"
                        max="<?= (int) $item['quantity'] ?>"
                        required
                    >

                    <button type="submit">Add to Cart</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
</div>

<?php include('includes/footer.php'); ?>
